<?php

/**
 * ycom
 *
 * Saml 2.0 Auth
 *
 * Needs data/addons/project/saml.json data for SP ( ServiceProvider )
 * Dummy here src/addons/ycom/plugins/auth/install/saml.json
 *
 * @author jan.kristinus[at]redaxo[dot]org Jan Kristinus
 * @author <a href="http://www.yakamara.de">www.yakamara.de</a>
 */

class rex_yform_value_ycom_auth_saml extends rex_yform_value_abstract
{
    private static $requestSAML = ['auth', 'sso', 'acs', 'slo', 'sls'];
    private $samlFile = 'saml.php';

    public function enterObject()
    {
        // TODO: sls und slo testen
        // TODO: Settings einlesen . Eigene Settingsverwaltung im Backend ?
        // TODO: if Metadataload failes -> get lokal xml ?

        if (PHP_SESSION_ACTIVE !== session_status()) {
            session_start();
        }

        /* @var $settings [] */
        include(\rex_addon::get('project')->getDataPath($this->samlFile));

        // load File IdP Metadata ? Optional
        // $idpInfo = \OneLogin\Saml2\IdPMetadataParser::parseFileXML(\rex_addon::get('project')->getDataPath('onelogin_metadata_993615.xml'));
        // dump($idpInfo);

        // load external Metadata
        $idpSettings = OneLogin\Saml2\IdPMetadataParser::parseRemoteXML($settings['idp']['entityId']);
        $mergedSettings = OneLogin\Saml2\IdPMetadataParser::injectIntoSettings($settings, $idpSettings);


        $returnTos = [];
        $returnTos[] = rex_request('returnTo', 'string', ''); // wenn returnTo übergeben wurde, diesen nehmen
        $returnTos[] = rex_getUrl(rex_config::get('ycom/auth', 'article_id_jump_ok'), '', [], '&'); // Auth Ok -> article_id_jump_ok / Current Language will be selected
        $returnTo = rex_ycom_auth::getReturnTo($returnTos, ($this->getElement(3) == '') ? [] : explode(',', $this->getElement(3)));

        $requestSAML = rex_request('rex_ycom_auth_saml', 'string', '');
        if ($this->needsOutput()) {
            // TODO: noch als Button über Fragmente bauen
            $this->params['form_output'][$this->getId()] = '<a href="'.rex_getUrl('', '', ['rex_ycom_auth_saml' => 'auth', 'returnTo' => $returnTo]).'">{{ saml_auth }}</a>';
        }
        if (!in_array($requestSAML, self::$requestSAML, true)) {
            return '';
        }

        // Auth
        $auth = new OneLogin\Saml2\Auth($mergedSettings);

        switch ($requestSAML) {

            // init login
            case 'sso':
                $returnToUrl = rex_yrewrite::getFullUrlByArticleId('', '', ['rex_ycom_auth_saml' => 'auth', 'returnTo' => $returnTo]);
                $ssoBuiltUrl = $auth->login($returnToUrl, [], false, false, true);
                rex_ycom_auth::setSessionVar('SAML_AuthNRequestID', $auth->getLastRequestID());
                rex_ycom_auth::setSessionVar('SAML_ssoDate', date('Y-m-d H:i:s'));
                rex_response::sendRedirect($ssoBuiltUrl);
                break;

            // process login
            case 'acs':
                $requestID = rex_ycom_auth::getSessionVar('SAML_AuthNRequestID', 'string', null);

                try {
                    $auth->processResponse($requestID);
                } catch (Throwable $e) {
                    if ($this->params['debug']) {
                        dump($e);
                    }
                    $this->params['warning_messages'][] = ($this->getElement(2) != '') ? $this->getElement(2) : '{{ saml.error.acs }}';
                    return '';
                }

                $errors = $auth->getErrors();
                if (!empty($errors) || !$auth->isAuthenticated()) {
                    if ($this->params['debug']) {
                        dump($errors);
                    }
                    $this->params['warning_messages'][] = ($this->getElement(2) != '') ? $this->getElement(2) : '{{ saml.error.acs }}';
                    return '';
                }

                rex_ycom_auth::setSessionVar('SAML_Userdata', $auth->getAttributes());
                rex_ycom_auth::setSessionVar('SAML_NameId', $auth->getNameId());
                rex_ycom_auth::setSessionVar('SAML_SessionIndex', $auth->getSessionIndex());
                rex_ycom_auth::setSessionVar('SAML_AuthNRequestID', '');

                session_write_close();

                if (isset($_POST['RelayState']) && OneLogin\Saml2\Utils::getSelfURL() != $_POST['RelayState']) {
                    $auth->redirectTo($_POST['RelayState']);
                }

                rex_redirect(rex_plugin::get('ycom', 'auth')->getConfig('article_id_jump_not_ok'));
                break;

            // init Logout processs with returnTo or redirect from idp
            case 'slo':

                $returnToURL = rex_yrewrite::getFullUrlByArticleId('', '', ['rex_ycom_auth_saml' => 'sls', 'returnTo' => $returnTo]);
                $parameters = [];

                $nameId = rex_ycom_auth::getSessionVar('SAML_NameId', 'string', null);
                $sessionIndex = rex_ycom_auth::getSessionVar('SAML_SessionIndex', 'string', null);
                $nameIdFormat = rex_ycom_auth::getSessionVar('SAML_NameIdFormat', 'string', null);

                rex_ycom_auth::clearUserSession();
                self::auth_saml_clearUserSession();

                $sloBuiltUrl = $auth->logout($returnToURL, $parameters, $nameId, $sessionIndex, true, $nameIdFormat);
                rex_ycom_auth::setSessionVar('SAML_LogoutRequestID', $auth->getLastRequestID());

                session_write_close(); // wirklich nötig ?

                rex_response::sendRedirect($sloBuiltUrl);
                break;

            // process Logout without returnTo
            case 'sls':

                $requestID = rex_ycom_auth::getSessionVar('SAML_LogoutRequestID', 'string', null);

                try {
                    $auth->processSLO(false, $requestID); // true => keep local session
                } catch (Throwable $e) {
                    if ($this->params['debug']) {
                        dump($e);
                    }
                    $this->params['warning_messages'][] = ($this->getElement(2) != '') ? $this->getElement(2) : '{{ saml.error.acs }}';
                    return '';
                }

                rex_ycom_auth::clearUserSession();
                self::auth_saml_clearUserSession();

                // returnTo setzen auf LogoutSeite
                $errors = $auth->getErrors();
                if (empty($errors)) {
                    // hier wird davon aufgegangen, dass immer ein returnTo gesetzt ist.
                    // \rex_yrewrite::getFullUrlByArticleId(\rex_plugin::get('ycom', 'auth')->getConfig('article_id_jump_logout'));
                    rex_response::sendRedirect($returnTo);

                } else {
                    if ($this->params['debug']) {
                        dump($errors);
                    }
                    $this->params['warning_messages'][] = ($this->getElement(2) != '') ? $this->getElement(2) : '{{ saml.error.sls }}';
                    return '';
                }
                break;
        }

        if (0 == count(rex_ycom_auth::getSessionVar('SAML_Userdata', 'array', []))) {
            // direkt durchschleifen zu SAML AUTH .. Hier könnte man auch eine abfrage machen.
            rex_response::sendRedirect(rex_getUrl('', '', ['rex_ycom_auth_saml' => 'sso', 'returnTo' => $returnTo], '&'));
            exit;
        }

        // TODO: Useranlegen/updaten / einloggen / verbieten
        // TODO. install -> saml.php -> project

        $Userdata = rex_ycom_auth::getSessionVar('SAML_Userdata', 'array', []);

        $data = [];
        $data['email'] = '';
        foreach(['User.email','emailAddress'] as $Key) {
            if (isset($Userdata[$Key])) {
                $data['email'] = implode(' ', $Userdata[$Key]);
            }
        }

        $data['firstname'] = '';
        foreach(['User.FirstName','givenName'] as $Key) {
            if (isset($Userdata[$Key])) {
                $data['firstname'] = implode(' ', $Userdata[$Key]);
            }
        }

        $data['name'] = '';
        foreach(['User.LastName','surName'] as $Key) {
            if (isset($Userdata[$Key])) {
                $data['name'] = implode(' ', $Userdata[$Key]);
            }
        }

        $data = rex_extension::registerPoint(new rex_extension_point('YCOM_AUTH_SAML_MATCHING', $data, ['Userdata' => $Userdata]));

        self::auth_saml_clearUserSession();

        // not logged in - check if available
        $params = [];
        $params['loginName'] = $data['email'];
        $params['loginStay'] = true;
        $params['filter'] = 'status > 0';
        $params['ignorePassword'] = true;

        $loginStatus = \rex_ycom_auth::login($params);
        if ($loginStatus == 2) {
            // already logged in
            rex_ycom_user::updateUser($data);
            \rex_response::sendRedirect($returnTo);
        }

        // if user not found, check if exists, but no permission
        $user = \rex_ycom_user::query()->where('email', $data['email'])->findOne();
        if ($user) {
            $this->params['warning_messages'][] = ($this->getElement(2) != '') ? $this->getElement(2) : '{{ saml.error.ycom_login_failed }}';
            return '';
        }

        $user = rex_ycom_user::createUserByEmail($data);
        if (!$user || count($user->getMessages()) > 0) {
            if ($this->params['debug']) {
                dump($user->getMessages());
            }
            $this->params['warning_messages'][] = ($this->getElement(2) != '') ? $this->getElement(2) : '{{ saml.error.ycom_create_user }}';
            return '';
        }

        $params = [];
        $params['loginName'] = $user->getValue('email');
        $params['ignorePassword'] = true;
        $loginStatus = \rex_ycom_auth::login($params);

        if ($loginStatus != 2) {
            if ($this->params['debug']) {
                dump($loginStatus);
                dump($user);
            }
            $this->params['warning_messages'][] = ($this->getElement(2) != '') ? $this->getElement(2) : '{{ saml.error.ycom_login_created_user }}';
            return '';
        }

        \rex_response::sendRedirect($returnTo);

    }

    public function getDescription()
    {
        return 'ycom_auth_saml|label|error_msg|[allowed returnTo domains: DomainA,DomainB]';
    }

    public static function auth_saml_clearUserSession()
    {
        rex_ycom_auth::unsetSessionVar('SAML_Userdata');
        rex_ycom_auth::unsetSessionVar('SAML_NameId');
        rex_ycom_auth::unsetSessionVar('SAML_SessionIndex');
        rex_ycom_auth::unsetSessionVar('SAML_AuthNRequestID');
        rex_ycom_auth::unsetSessionVar('SAML_LogoutRequestID');
        rex_ycom_auth::unsetSessionVar('SAML_NameIdFormat');
        rex_ycom_auth::unsetSessionVar('SAML_ssoDate');
    }
}
