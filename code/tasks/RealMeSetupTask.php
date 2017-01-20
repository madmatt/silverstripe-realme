<?php

/**
 * Class RealMeSetupTask
 *
 * This class is intended to be run by a server administrator once the module is setup and configured via environment
 * variables, and YML fragments. The following tasks are done by this build task:
 *
 * - Check to ensure that the task is being run from the cmdline (not in the browser, it's too sensitive)
 * - Check to ensure that the task hasn't already been run, and if it has, fail unless `force=1` is passed to the script
 * - Validate all required values have been added in the appropriate place, and provide appropriate errors if not
 * - Output metadata XML that must be submitted to RealMe in order to integrate with ITE and Production environments
 */
class RealMeSetupTask extends BuildTask
{
    protected $title = "RealMe Setup Task";

    protected $description = 'Validates a realme configuration & creates the resources needed to integrate with realme';

    /**
     * @var RealMeService
     */
    private $service;

    /**
     * A list of validation errors found while validating the realme configuration.
     *
     * @var string[]
     */
    private $errors = array();

    /**
     * Run this setup task. See class phpdoc for the full description of what this does
     *
     * @param SS_HTTPRequest $request
     */
    public function run($request)
    {
        try {
            $this->service = Injector::inst()->get('RealMeService');

            // Ensure we are running on the command-line, and not running in a browser
            if (false === Director::is_cli()) {
                throw new Exception(_t('RealMeSetupTask.ERR_NOT_CLI'));
            }

            // Validate all required values exist
            $forEnv = $request->getVar('forEnv');

            // Throws an exception if there was a problem with the config.
            $this->validateInputs($forEnv);

            $this->outputMetadataXmlContent($forEnv);

            $this->message(PHP_EOL . _t('RealMeSetupTask.BUILD_FINISH', '', '', array('env' => $forEnv)));
        } catch (Exception $e) {
            $this->message($e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Validate all inputs to this setup script. Ensures that all required values are available, where-ever they need to
     * be loaded from (environment variables, Config API, or directly passed to this script via the cmd-line)
     *
     * @param string $forEnv The environment that we want to output content for (mts, ite, or prod)
     *
     * @throws Exception if there were errors with the request or setup format.
     */
    private function validateInputs($forEnv)
    {
        // Ensure that 'forEnv=' is specified on the cli, and ensure that it matches a RealMe environment
        $this->validateRealMeEnvironments($forEnv);

        // Ensure we have the necessary directory structures, and their visibility
        $this->validateDirectoryStructure();

        // Ensure we have the certificates in the correct places.
        $this->validateCertificates();

        // Ensure the entityID is valid, and the privacy realm and service name are correct
        $this->validateEntityID();

        // Make sure we have an authncontext for each environment.
        $this->validateAuthNContext();

        // Ensure data required for metadata XML output exists
        $this->validateMetadata();

        // Output validation errors, if any are found
        if (sizeof($this->errors) > 0) {
            $errorList = PHP_EOL . ' - ' . join(PHP_EOL . ' - ', $this->errors);

            throw new Exception(_t(
                'RealMeSetupTask.ERR_VALIDATION',
                '',
                '',
                array(
                    'numissues' => sizeof($this->errors),
                    'issues' => $errorList
                )
            ));
        }

        $this->message(_t('RealMeSetupTask.VALIDATION_SUCCESS'));
    }

    /**
     * Outputs metadata template XML to console, so it can be sent to RealMe Operations team
     *
     * @param string $forEnv The RealMe environment to output metadata content for (e.g. mts, ite, prod).
     */
    private function outputMetadataXmlContent($forEnv)
    {
        // Output metadata XML so that it can be sent to RealMe via the agency
        $this->message(sprintf(
            "Metadata XML is listed below for the '%s' RealMe environment, this should be sent to the agency so they "
                . "can pass it on to RealMe Operations staff" . PHP_EOL . PHP_EOL,
            $forEnv
        ));

        $configDir = $this->getConfigurationTemplateDir();
        $templateFile = Controller::join_links($configDir, 'metadata.xml');

        if (false === $this->isReadable($templateFile)) {
            throw new Exception(sprintf("Can't read metadata.xml file at %s", $templateFile));
        }

        $supportContact = $this->service->getMetadataContactSupport();

        $message = $this->replaceTemplateContents(
            $templateFile,
            array(
                '{{entityID}}' => $this->service->getSPEntityID(),
                '{{certificate-data}}' => $this->service->getSPCertContent(),
                '{{acs-url}}' => $this->service->getAssertionConsumerServiceUrlForEnvironment($forEnv),
                '{{organisation-name}}' => $this->service->getMetadataOrganisationName(),
                '{{organisation-display-name}}' => $this->service->getMetadataOrganisationDisplayName(),
                '{{organisation-url}}' => $this->service->getMetadataOrganisationUrl(),
                '{{contact-support1-company}}' => $supportContact['company'],
                '{{contact-support1-firstnames}}' => $supportContact['firstNames'],
                '{{contact-support1-surname}}' => $supportContact['surname'],
            )
        );

        $this->message($message);
    }

    /**
     * Replace content in a template file with an array of replacements
     *
     * @param string $templatePath The path to the template file
     * @param array|null $replacements An array of '{{variable}}' => 'value' replacements
     * @return string The contents, with all {{variables}} replaced
     */
    private function replaceTemplateContents($templatePath, $replacements = null)
    {
        $configText = file_get_contents($templatePath);

        if (true === is_array($replacements)) {
            $configText = str_replace(array_keys($replacements), array_values($replacements), $configText);
        }

        return $configText;
    }

    /**
     * @return string The full path to RealMe configuration
     */
    private function getConfigurationTemplateDir()
    {
        $dir = $this->config()->template_config_dir;
        $path = Controller::join_links(BASE_PATH, $dir);

        if ($dir && false !== $this->isReadable($path)) {
            return $path;
        }

        return Controller::join_links(BASE_PATH, REALME_MODULE_PATH . '/templates/saml-conf');
    }

    /**
     * Output a message to the console
     * @param string $message
     * @return void
     */
    private function message($message)
    {
        echo $message . PHP_EOL;
    }

    /**
     * Thin wrapper around is_readable(), used mainly so we can test this class completely
     *
     * @param string $filename The filename or directory to test
     * @return bool true if the file/dir is readable, false if not
     */
    private function isReadable($filename)
    {
        return is_readable($filename);
    }

    /**
     * The entity ID will pass validation, but raise an exception if the format of the service name and privacy realm
     * are in the incorrect format.
     * The service name and privacy realm need to be under 10 chars eg.
     * http://hostname.domain/serviceName/privacyRealm
     *
     * @return void
     */
    private function validateEntityID()
    {
        $entityId = $this->service->getSPEntityID();

        if (is_null($entityId)) {
            $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_NO_ENTITYID', '', '', array('env' => $env));
        }

        // make sure the entityID is a valid URL
        $entityId = filter_var($entityId, FILTER_VALIDATE_URL);
        if ($entityId === false) {
            $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_ENTITYID', '', '',
                array(
                    'entityId' => $entityId
                )
            );

            // invalid entity id, no point continuing.
            return;
        }

        // check it's not localhost and HTTPS. and make sure we have a host / scheme
        $urlParts = parse_url($entityId);
        if ($urlParts['host'] === 'localhost' || $urlParts['scheme'] === 'http') {
            $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_ENTITYID', '', '',
                array(
                    'entityId' => $entityId
                )
            );

            // if there's this much wrong, we want them to fix it first.
            return;
        }

        $path = ltrim($urlParts['path']);
        $urlParts = preg_split("/\\//", $path);


        // A valid Entity ID is in the form of "https://www.domain.govt.nz/<privacy-realm>/<service-name>"
        // Validate Service Name
        $serviceName = array_pop($urlParts);
        if (mb_strlen($serviceName) > 20 || 0 === mb_strlen($serviceName)) {
            $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_ENTITYID_SERVICE_NAME', '', '',
                array(
                    'serviceName' => $serviceName,
                    'entityId' => $entityId
                )
            );
        }

        // Validate Privacy Realm
        $privacyRealm = array_pop($urlParts);
        if (null === $privacyRealm || 0 === mb_strlen($privacyRealm)) {
            $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_ENTITYID_PRIVACY_REALM', '', '',
                array(
                    'privacyRealm' => $privacyRealm,
                    'entityId' => $entityId
                )
            );
        }
    }

    /**
     * Ensure we have an authncontext (how secure auth we require for each environment)
     *
     * e.g. urn:nzl:govt:ict:stds:authn:deployment:GLS:SAML:2.0:ac:classes:LowStrength
     */
    private function validateAuthNContext()
    {
        foreach ($this->service->getAllowedRealMeEnvironments() as $env) {
            $context = $this->service->getAuthnContextForEnvironment($env);
            if (is_null($context)) {
                $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_NO_AUTHNCONTEXT', '', '', array('env' => $env));
            }

            if (!in_array($context, $this->service->getAllowedAuthNContextList())) {
                $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_INVALID_AUTHNCONTEXT', '', '', array('env' => $env));
            }
        }
    }

    /**
     * Ensure's the environment we're building the setup for exists.
     *
     * @param $forEnv string
     */
    private function validateRealMeEnvironments($forEnv)
    {
        $allowedEnvs = $this->service->getAllowedRealMeEnvironments();
        if (0 === mb_strlen($forEnv)) {
            $this->errors[] = _t(
                'RealMeSetupTask.ERR_ENV_NOT_SPECIFIED',
                '',
                '',
                array(
                    'allowedEnvs' => join(', ', $allowedEnvs)
                )
            );
            return;
        }

        if (false === in_array($forEnv, $allowedEnvs)) {
            $this->errors[] = _t(
                'RealMeSetupTask.ERR_ENV_NOT_ALLOWED',
                '',
                '',
                array(
                    'env' => $forEnv,
                    'allowedEnvs' => join(', ', $allowedEnvs)
                )
            );
        }
    }

    /**
     * Ensures that the directory structure is correct and the necessary directories are writable.
     */
    private function validateDirectoryStructure()
    {
        if (is_null($this->service->getCertDir())) {
            $this->errors[] = _t('RealMeSetupTask.ERR_CERT_DIR_MISSING');
        } elseif (!$this->isReadable($this->service->getCertDir())) {
            $this->errors[] = _t(
                'RealMeSetupTask.ERR_CERT_DIR_NOT_READABLE',
                '',
                '',
                array('dir' => $this->service->getCertDir())
            );
        }
    }

    /**
     * Ensures that the required metadata is filled out correctly in the realme configuration.
     */
    private function validateMetadata()
    {
        if (is_null($this->service->getMetadataOrganisationName())) {
            $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_NO_ORGANISATION_NAME');
        }

        if (is_null($this->service->getMetadataOrganisationDisplayName())) {
            $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_NO_ORGANISATION_DISPLAY_NAME');
        }

        if (is_null($this->service->getMetadataOrganisationUrl())) {
            $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_NO_ORGANISATION_URL');
        }

        $contact = $this->service->getMetadataContactSupport();
        if (is_null($contact['company']) || is_null($contact['firstNames']) || is_null($contact['surname'])) {
            $this->errors[] = _t('RealMeSetupTask.ERR_CONFIG_NO_SUPPORT_CONTACT');
        }
    }

    /**
     * Ensures the certificates are readable and that the service can sign and unencrypt using them
     */
    private function validateCertificates()
    {
        $signingCertFile = $this->service->getSigningCertPath();
        if (is_null($signingCertFile) || !$this->isReadable($signingCertFile)) {
            $this->errors[] = _t(
                'RealMeSetupTask.ERR_CERT_NO_SIGNING_CERT',
                '',
                '',
                array(
                    'const' => 'REALME_SIGNING_CERT_FILENAME'
                )
            );
        } elseif (is_null($this->service->getSPCertContent())) {
            // Signing cert exists, but doesn't include BEGIN/END CERTIFICATE lines, or doesn't contain the cert
            $this->errors[] = _t(
                'RealMeSetupTask.ERR_CERT_SIGNING_CERT_CONTENT',
                '',
                '',
                array('file' => $this->service->getSigningCertPath())
            );
        }
    }
}
