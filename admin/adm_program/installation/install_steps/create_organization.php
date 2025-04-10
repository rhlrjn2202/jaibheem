<?php
/**
 ***********************************************************************************************
 * Installation step: create_organization
 *
 * @copyright 2004-2018 The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
if (basename($_SERVER['SCRIPT_FILENAME']) === 'create_organization.php')
{
    exit('This page may not be called directly!');
}

if (isset($_POST['db_host']))
{
    // PHP-Check Regex-Patterns
    $sqlIdentifiersRegex = '/^[a-zA-Z0-9_$@-]+$/';

    // Zugangsdaten der DB in Sessionvariablen gefiltert speichern
    $_SESSION['db_engine']    = $_POST['db_engine'];
    $_SESSION['db_host']      = $_POST['db_host'];
    $_SESSION['db_port']      = $_POST['db_port'];
    $_SESSION['db_name']      = $_POST['db_name'];
    $_SESSION['db_username']  = $_POST['db_username'];
    $_SESSION['db_password']  = $_POST['db_password'];
    $_SESSION['table_prefix'] = $_POST['table_prefix'];

    if ($_SESSION['db_engine']    === ''
    ||  $_SESSION['db_host']      === ''
    ||  $_SESSION['db_name']      === ''
    ||  $_SESSION['db_username']  === ''
    ||  $_SESSION['table_prefix'] === '')
    {
        showNotice(
            $gL10n->get('INS_DATABASE_CONNECTION_NOT_COMPLETELY'),
            safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'connect_database')),
            $gL10n->get('SYS_BACK'),
            'layout/back.png'
        );
        // => EXIT
    }

    // Check DB-type
    if (!in_array($_SESSION['db_engine'], array(Database::PDO_ENGINE_MYSQL, Database::PDO_ENGINE_PGSQL), true))
    {
        showNotice(
            $gL10n->get('INS_DATABASE_TYPE_INVALID'),
            safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'connect_database')),
            $gL10n->get('SYS_BACK'),
            'layout/back.png'
        );
        // => EXIT
    }

    // Check host
    // TODO: unix_server is currently not supported
    if (filter_var($_SESSION['db_host'], FILTER_VALIDATE_DOMAIN) === false && filter_var($_SESSION['db_host'], FILTER_VALIDATE_IP) === false)
    {
        showNotice(
            $gL10n->get('INS_HOST_INVALID'),
            safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'connect_database')),
            $gL10n->get('SYS_BACK'),
            'layout/back.png'
        );
        // => EXIT
    }

    // Check port
    if ($_SESSION['db_port'] === '' || $_SESSION['db_port'] === null)
    {
        $_SESSION['db_port'] = null;
    }
    elseif (is_numeric($_SESSION['db_port']) && (int) $_SESSION['db_port'] > 0 && (int) $_SESSION['db_port'] <= 65535)
    {
        $_SESSION['db_port'] = (int) $_SESSION['db_port'];
    }
    else
    {
        showNotice(
            $gL10n->get('INS_DATABASE_PORT_INVALID'),
            safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'connect_database')),
            $gL10n->get('SYS_BACK'),
            'layout/back.png'
        );
        // => EXIT
    }

    // Check database
    if (strlen($_SESSION['db_name']) > 64 || preg_match($sqlIdentifiersRegex, $_SESSION['db_name']) !== 1)
    {
        showNotice(
            $gL10n->get('INS_DATABASE_NAME_INVALID'),
            safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'connect_database')),
            $gL10n->get('SYS_BACK'),
            'layout/back.png'
        );
        // => EXIT
    }

    // Check user
    if (strlen($_SESSION['db_username']) > 64 || preg_match($sqlIdentifiersRegex, $_SESSION['db_username']) !== 1)
    {
        showNotice(
            $gL10n->get('INS_DATABASE_USER_INVALID'),
            safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'connect_database')),
            $gL10n->get('SYS_BACK'),
            'layout/back.png'
        );
        // => EXIT
    }

    // Check password
    $zxcvbnScore = PasswordHashing::passwordStrength($_SESSION['db_password']);
    if ($zxcvbnScore <= 2)
    {
        $gLogger->warning('Database password is weak! (zxcvbn lib)', array('score' => $zxcvbnScore));
    }

    // Check prefix
    if (strlen($_SESSION['table_prefix']) > 10 || preg_match($sqlIdentifiersRegex, $_SESSION['table_prefix']) !== 1)
    {
        showNotice(
            $gL10n->get('INS_TABLE_PREFIX_INVALID'),
            safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'connect_database')),
            $gL10n->get('SYS_BACK'),
            'layout/back.png'
        );
        // => EXIT
    }

    // for security reasons only check database connection if no config file exists
    if (!is_file($configPath))
    {
        // check database connections
        try
        {
            $db = new Database($_SESSION['db_engine'], $_SESSION['db_host'], $_SESSION['db_port'], $_SESSION['db_name'], $_SESSION['db_username'], $_SESSION['db_password']);
        }
        catch (AdmException $e)
        {
            showNotice(
                $gL10n->get('SYS_DATABASE_NO_LOGIN', array($e->getText())),
                safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'connect_database')),
                $gL10n->get('SYS_BACK'),
                'layout/back.png'
            );
            // => EXIT
        }

        // check database version
        $message = checkDatabaseVersion($db);
        if ($message !== '')
        {
            showNotice($message, safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'connect_database')), $gL10n->get('SYS_BACK'), 'layout/back.png');
            // => EXIT
        }

        // now check if a valid installation exists.
        $sql = 'SELECT org_id FROM ' . $_SESSION['table_prefix'] . '_organizations';
        $pdoStatement = $db->queryPrepared($sql, array(), false);

        if ($pdoStatement !== false && $pdoStatement->rowCount() > 0)
        {
            // valid installation exists -> exit installation
            showNotice(
                $gL10n->get('INS_INSTALLATION_EXISTS'),
                ADMIDIO_URL . '/adm_program/index.php',
                $gL10n->get('SYS_OVERVIEW'),
                'layout/application_view_list.png'
            );
            // => EXIT
        }
    }
}

// create a page to enter the organization names
$form = new HtmlFormInstallation('installation-form', safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'create_administrator')));

// initialize form data
$shortnameProperty = HtmlForm::FIELD_REQUIRED;

if (isset($_SESSION['orga_shortname']))
{
    $orgaShortName = $_SESSION['orga_shortname'];
    $orgaLongName  = $_SESSION['orga_longname'];
    $orgaEmail     = $_SESSION['orga_email'];
}
else
{
    $orgaShortName = $g_organization;
    $orgaLongName  = '';
    $orgaEmail     = '';

    if ($g_organization !== '')
    {
        $shortnameProperty = HtmlForm::FIELD_READONLY;
    }
}

// create array with possible PHP timezones
$allTimezones = \DateTimeZone::listIdentifiers();
$timezones = array();
foreach ($allTimezones as $timezone)
{
    $timezones[$timezone] = $timezone;
}

$form->setFormDescription($gL10n->get('ORG_NEW_ORGANIZATION_DESC'), $gL10n->get('INS_SET_ORGANIZATION'));
$form->openGroupBox('gbChooseLanguage', $gL10n->get('INS_DATA_OF_ORGANIZATION'));
$form->addInput(
    'orga_shortname', $gL10n->get('SYS_NAME_ABBREVIATION'), $orgaShortName,
    array('maxLength' => 10, 'property' => $shortnameProperty, 'class' => 'form-control-small')
);
$form->addInput(
    'orga_longname', $gL10n->get('SYS_NAME'), $orgaLongName,
    array('maxLength' => 50, 'property' => HtmlForm::FIELD_REQUIRED)
);
$form->addInput(
    'orga_email', $gL10n->get('ORG_SYSTEM_MAIL_ADDRESS'), $orgaEmail,
    array('type' => 'email', 'maxLength' => 50, 'property' => HtmlForm::FIELD_REQUIRED)
);
$form->addSelectBox(
    'orga_timezone', $gL10n->get('ORG_TIMEZONE'), $timezones,
    array('property' => HtmlForm::FIELD_REQUIRED, 'defaultValue' => date_default_timezone_get())
);
$form->closeGroupBox();
$form->addButton(
    'previous_page', $gL10n->get('SYS_BACK'),
    array('icon' => 'layout/back.png', 'link' => safeUrl(ADMIDIO_URL . '/adm_program/installation/installation.php', array('step' => 'connect_database')))
);
$form->addSubmitButton('next_page', $gL10n->get('INS_CREATE_ADMINISTRATOR'), array('icon' => 'layout/forward.png'));
echo $form->show();
