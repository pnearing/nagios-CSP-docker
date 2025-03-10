<?php
//
// Copyright (c) 2008-2024 Nagios Enterprises, LLC. All rights reserved.
//

require_once(dirname(__FILE__) . '/../includes/common.inc.php');

// Initialization stuff
pre_init();
init_session();

// Grab GET or POST variables, check prereqs, and authorization
grab_request_vars();
check_prereqs();
check_authentication(false);

// Only admins can access this page
if (is_admin() == false) {
    echo _("You are not authorized to access this feature. Contact your system administrator for more information, or to obtain access to this feature.");
    exit();
}

/* future react
route_to_neptune();
*/

route_request();


function route_request()
{
    global $request;

    if (isset($request['update'])) {
        do_update_user();
    } else if (isset($request['delete']) || (isset($request['multiButton']) && $request['multiButton'] == 'delete')) {
        do_delete_user();
    } else if (isset($request['unlock']) || (isset($request['multiButton']) && $request['multiButton'] == 'unlock')) {
        do_unlock_user();
    } else if (isset($request['toggle_active'])) {
        do_toggle_active_user();
    } else if (isset($request['edit'])) {
        show_edit_user();
    } else if (isset($request['clone'])) {
        show_clone_user();
    } else if (isset($request['doclone'])) {
        do_clone_user();
    } else if (isset($request['masquerade'])) {
        do_masquerade();
    } else if (isset($request['send_emails'])) {
        do_email_users();
    } else {
        show_users();
    }
    exit;
}


// Email all users
function do_email_users()
{
    $email_subject = grab_request_var("email_subject", "");
    $email_message = grab_request_var("email_message", "");
    $user_emails = grab_request_var("email_user_emails", "");

    // Check if emailing all users or not
    if ($user_emails == "all") {

        // Do a quick query to grab the users's email addresses
        $sql = "SELECT * FROM xi_users WHERE TRUE ORDER BY xi_users.email ASC";
        $rs = exec_sql_query(DB_NAGIOSXI, $sql);

        $user_emails = array();
        foreach ($rs as $user) {
            $user_emails[] = $user['email'];
        }

    } else {
        $user_emails = explode(",", $user_emails);
    }

    // Verify that we have stuff to send...
    $error = false;
    if (empty($email_subject) || empty($email_message)) {
        $error = true;
        $msg = _("Failed to send email. No subject or message was given.");
    }

    // Verify that we have some user emails
    if (empty($user_emails[0]) && count($user_emails) == 1) {
        $error = true;
        $msg = _("Failed to send email. No users selected to send to.");
    }

    // Use this for debug output in PHPmailer log
    $debugmsg = "";

    // Set where email is coming from for PHPmailer log
    $send_mail_referer = "admin/users.php > Email All Users";

    // Send to each user individually
    foreach ($user_emails as $email) {

        // Send email to user...
        $opts = array("to" => $email,
            "from" => "",
            "subject" => $email_subject,
            "message" => $email_message);
        send_email($opts, $debugmsg, $send_mail_referer);

    }

    if (!$error) {
        $msg = "Email(s) have been sent.";
        send_to_audit_log(_('Sent email to users from user page'), AUDITLOGTYPE_INFO);
    }
    show_users($error, $msg);
}

/**
 * Shows the table list view of all the users for the XI system.
 *
 * @param bool   $error
 * @param string $msg
 */
function show_users($error = false, $msg = "")
{
    global $request;
    global $db_tables;
    global $sqlquery;
    global $cfg;

    // Generate messages...
    if ($msg == "") {
        if (isset($request["useradded"])) {
            $msg = _("User Added.");
        }
        if (isset($request["userupdated"])) {
            $msg = _("User Updated.");
        }
        if (isset($request["usercloned"])) {
            $msg = _("User cloned.");
        }
    }

    // Defaults
    $sortby = "username";
    $sortorder = "asc";
    $page = 1;
    $records = 5;
    $search = '';

    // Default to use saved options
    $s = get_user_meta(0, 'user_management_options');
    $saved_options = unserialize($s);
    if (is_array($saved_options)) {
        if (isset($saved_options["sortby"])) {
            $sortby = $saved_options["sortby"];
        }
        if (isset($saved_options["sortorder"])) {
            $sortorder = $saved_options["sortorder"];
        }
        if (isset($saved_options["records"])) {
            $records = $saved_options["records"];
        }
        if (array_key_exists("search", $saved_options)) {
            $search = $saved_options["search"];
        }
    }

    // Get options
    $sortby = grab_request_var("sortby", $sortby);
    $sortorder = grab_request_var("sortorder", $sortorder);
    $page = grab_request_var("page", $page);
    $records = grab_request_var("records", $records);
    $user_id = grab_request_var("user_id", array());
    $search = trim(grab_request_var("search", $search));
    if ($search == _("Search...")) {
        $search = "";
    }

    // Save options for later
    $saved_options = array(
        "sortby" => $sortby,
        "sortorder" => $sortorder,
        "records" => $records,
        "search" => $search
    );
    $s = serialize($saved_options);
    set_user_meta(0, 'user_management_options', $s, false);

    // Generate query
    $fieldmap = array(
        "username" => $db_tables[DB_NAGIOSXI]["users"] . ".username",
        "name" => $db_tables[DB_NAGIOSXI]["users"] . ".name",
        "email" => $db_tables[DB_NAGIOSXI]["users"] . ".email",
        "last_login" => $db_tables[DB_NAGIOSXI]["users"] . ".last_login",
        "auth_level" =>  "auth_level",
        "auth_type" =>  "auth_type"
    );
    $query_args = array();
    if (isset($sortby)) {
        $query_args["orderby"] = $sortby;
        if (isset($sortorder) && $sortorder == "desc") {
            $query_args["orderby"] .= ":d";
        } else {
            $query_args["orderby"] .= ":a";
        }
    }
    if (isset($search) && have_value($search)) {
        $query_args["username"] = "lk:" . $search . ";name=lk:" . $search . ";email=lk:" . $search;
    }

    // First get record count
    $sql_args = array(
        "sql" => $sqlquery['GetUsersWithUserMeta'],
        "fieldmap" => $fieldmap,
        "default_order" => "username",
        "useropts" => $query_args,
        "limitrecords" => false
    );
    $sql = generate_sql_query(DB_NAGIOSXI, $sql_args);
    $rs = exec_sql_query(DB_NAGIOSXI, $sql);
    if (!$rs->EOF) {
        $total_records = $rs->RecordCount();
    } else {
        $total_records = 0;
    }

    // get any locked account info
    $locked_accounts = locked_account_list();

    // Get table paging info - reset page number if necessary
    $pager_args = array(
        "sortby" => $sortby,
        "sortorder" => $sortorder,
        "search" => $search
    );
    $pager_results = get_table_pager_info("", $total_records, $page, $records, $pager_args);

    do_page_start(array("page_title" => _("Manage Users")), true);

    ?>
    <h1><?= _("Manage Users"); ?></h1>

    <?php
    display_message($error, false, $msg);
    ?>

    <form action="users.php" method="post" id="userList">
        <?= get_nagios_session_protector(); ?>
        <input type="hidden" name="sortby" value="<?= encode_form_val($sortby); ?>">
        <input type="hidden" name="sortorder" value="<?= encode_form_val($sortorder); ?>">

        <div id="usersTableContainer" class="tableContainer">

            <div class="tableHeader">

                <div class="tableTopButtons new-buttons btn-row" style="margin-top: 10px;">
                    <a href="?users&amp;edit=1" class="btn btn-sm btn-primary btn-icon-and-text">
                        <span class="material-symbols-outlined tableTopButton" title="<?= _("Add New User") ?>">person_add</span>
                        <span><?= _("Add New User"); ?></span>
                    </a>

                    <?php if (is_component_installed("ldap_ad_integration")) { ?>
                    <a href="<?= get_component_url_base("ldap_ad_integration"); ?>/index.php" class="btn btn-sm btn-primary btn-icon-and-text">
                        <span class="material-symbols-outlined tableTopButton" title="<?= _("Add users from LDAP/AD") ?>">contact_mail</span>
                        <span><?= _("Add users from LDAP/AD"); ?></span>
                    </a>
                    <?php } ?>

                    <a href="#" onclick="users_display_email_selected(true)" class="btn btn-sm btn-primary btn-icon-and-text">
                        <span class="material-symbols-outlined tableTopButton" title="<?= _("Send Email to All Users") ?>">outgoing_mail</span>
                        <span><?= _("Email All Users"); ?></span>
                    </a>

                    <div class="tableListSearchFlex">
<?php
                        $searchclass = "textfield";
                        $searchstring = '';
                        if (have_value($search)) {
                            $searchstring = $search;
                            $searchclass .= " newdata";
                        }

                        if (!is_neptune()) {
?>
                        <input type="text" size="15" name="search" id="searchBox" value="<?= encode_form_val($searchstring); ?>" placeholder="Search..." class="<?= $searchclass; ?> form-control va-m">
                        <button type="submit" class="btn btn-sm btn-default btn-flex" name="searchButton" id="searchButton"><span class="material-symbols-outlined">search</span></button>
<?php                            
                        } else {
?>
                        <div class="neptune-form-element-wrapper input-group">
                            <input type="text" name="search" id="searchBox" value="<?= encode_form_val($searchstring); ?>" placeholder="Search..." class="<?= $searchclass; ?> form-control va-m">
                            <span class="input-group-btn">
                                <button type="submit" class="btn btn-sm btn-default btn-flex" name="searchButton" id="searchButton">
                                    <span class="material-symbols-outlined">search</span>
                                </button>
                            </span>
                        </div>
<?php                            
                        }
?>
                    </div>
                    <!--table list search -->
                </div>
                <!-- table top buttons -->

                <div class="tableTopText">
<?php
                    $clear_args = array(
                        "sortby" => $sortby,
                        "search" => ""
                    );
                    echo table_record_count_text($pager_results, $search, true, $clear_args);
?>
                </div>

                <br/>

            </div>
            <!-- tableHeader -->

            <table id="usersTable" class="tablesorter table table-striped table-hover table-condensed table-bordered">
                <thead>
                    <tr>
                        <th style="text-align: center;">
                            <input type='checkbox' name='userList_checkAll' id='checkall' value='0'>
                        </th>
                        <th><!-- disabled icons --></th>
<?php
                        $extra_args = array();
                        $extra_args["search"] = $search;
                        $extra_args["records"] = $records;
                        $extra_args["page"] = $page;
                        echo sorted_table_header($sortby, $sortorder, "username", _('Username'), $extra_args, "", "users.php");
                        echo sorted_table_header($sortby, $sortorder, "name", _('Name'), $extra_args, "", "users.php");
                        echo sorted_table_header($sortby, $sortorder, "email", _('Email'), $extra_args, "", "users.php");
?>
                        <th><?= _('Phone Number'); ?></th>
<?php
                        echo sorted_table_header($sortby, $sortorder, "auth_level", _('Auth Level'), $extra_args, "", "users.php");
                        echo sorted_table_header($sortby, $sortorder, "auth_type", _('Auth Type'), $extra_args, "", "users.php");
                        echo sorted_table_header($sortby, $sortorder, "last_login", _('Last Login'), $extra_args, "", "users.php");
?>
                        <th style="width: 140px;"><?= _('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
<?php
                // Run record-limiting query
                $query_args["records"] = $records . ":" . (($pager_results["current_page"] - 1) * $records);
                $sql_args["sql"] = $sql;
                $sql_args["useropts"] = $query_args;
                $sql = limit_sql_query_records($sql_args, DB_NAGIOSXI);
                $rs = exec_sql_query(DB_NAGIOSXI, $sql);
                $authlevels = get_authlevels();
                $authtypes = array('ad' => _('Active Directory'), 'ldap' => _('LDAP'), 'local' => _('Local'));

                $cloud = false;
                if (is_v2_license_type('cloud')) {
                    $cloud = true;
                }

                $x = 0;

                if (!$rs || $rs->EOF) {
                    echo "<tr><td colspan='9'>" . _('No records found') . ".</td></tr>";
                } else {
                    while (!$rs->EOF) {
                        $oid = $rs->fields["user_id"];
                        $username = $rs->fields["username"];

                        // Check for cloud "nagioscloud" admin user
                        if ($cloud) {
                            if ($oid == 1 && $username == "nagioscloud") {
                                $rs->MoveNext();
                                continue;
                            }
                        }
                        
                        $checked = "";
                        $classes = "";

                        $user_enabled = $rs->fields["enabled"];                        
                        $user_status_icon = "<i class='tt-bind material-symbols-outlined md-20 md-ok md-400 md-middle' border='0' alt='" . _("Enabled") . "' title='" . _("Account is enabled") . "'>check_circle</span>";

                        if ($user_enabled == 0) {                            
                            $user_status_icon = "<span class='tt-bind material-symbols-outlined md-20 md-critical md-400 md-middle' title='" . _("Account is disabled") . "'>error</span>";
                        }

                        if (is_array($user_id)) {
                            if (in_array($oid, $user_id)) {
                                $checked = "CHECKED";
                                $classes .= " selected";
                            }
                        } else if ($oid == $user_id) {
                            $checked = "CHECKED";
                            $classes .= " selected";
                        }

                        $last_login = '-';
                        if (!empty($rs->fields['last_login'])) {
                            $last_login = get_datetime_string($rs->fields['last_login']);
                        }

                        $phone = encode_form_val(get_user_meta($oid, 'mobile_number', ''));
                        $vtag = get_user_phone_vtag($oid);
                        if (!empty($phone)) {
                            $phone_with_tag = "$phone $vtag";
                        } else {
                            $phone_with_tag = "-";
                        }
?>
                    <tr <?= (have_value($classes) ? 'class="'.$classes.'"' : "") ?>>
                        <td style='text-align: center;'>
                            <input type='checkbox' class='uidcheckbox' name='user_id[]' data-email='<?= encode_form_val($rs->fields["email"]) ?>' value='<?= $oid ?>' id='checkbox_<?= $oid ?>' <?= $checked ?> <?= (!is_neptune() ? "style='display: inline-block; padding: 0; margin: 0; vertical-align: middle;'" : "") ?>>
                        </td>
                        <td style='text-align: center; padding: 4px;'><?= $user_status_icon ?></td>
                        <td class="clickable">
                            <a href="?edit=1&amp;user_id[]=<?= $oid ?>"> <?= encode_form_val_minimal($username) ?></a>
                        </td>
                        <td class='clickable'><?= encode_form_val_minimal($rs->fields["name"]) ?></td>
                        <td class='clickable'><a href='mailto:<?= encode_form_val($rs->fields["email"]) ?>'><?= encode_form_val($rs->fields["email"]) ?></a></td>
                        <td><?= $phone_with_tag ?></td>
                        <td class='clickable'><?= $authlevels[encode_form_val($rs->fields["auth_level"])] ?></td>
                        <td class='clickable'>
                            <?= $authtypes[isset($rs->fields["auth_type"]) ? encode_form_val($rs->fields["auth_type"]) : "local"] ?>
                        </td>
                        <td><?= $last_login ?></td>
                        <td class="neptune-td-nowrap actionCell">
                            <a href='?edit=1&amp;user_id[]=<?= $oid ?>'><span class='material-symbols-outlined tableItemButton md-action md-button md-400 md-20 md-middle tt-bind' title='<?= _("Edit") ?>'>edit_square</span></a>
                            <a href='?clone=1&amp;user_id[]=<?= $oid ?>'><span class='material-symbols-outlined tableItemButton md-action md-button md-400 md-20 md-middle tt-bind' title='<?= _("Clone") ?>'>content_copy</span></a>
<?php
                        if ($user_enabled > 0) {
?>
                            <a href='?masquerade=1&user_id=<?= $oid ?>&nsp="<?= get_nagios_session_protector_id() ?>' class='masquerade_link'><span class='material-symbols-outlined tableItemButton md-action md-button md-400 md-20 md-middle tt-bind' title='<?= _("Masquerade As") ?>'>domino_mask</span></a>
                            <a href='?toggle_active=0&amp;user_id=<?= $oid ?>&nsp=<?= get_nagios_session_protector_id() ?>'><span class='material-symbols-outlined tableItemButto md-action md-button md-400 md-20 md-middle tt-bind' title='<?= _("Disable") ?>'>person_cancel</span></a>
<?php
                        } else {
?>
                            <a href='?toggle_active=1&amp;user_id=<?= $oid ?>&nsp=<?= get_nagios_session_protector_id() ?>'><span class='material-symbols-outlined tableItemButton md-action md-button md-400 md-20 md-middle tt-bind' title='<?= _("Enable") ?>'>person_check</span></a>
<?php
                        }

                        if (is_array($locked_accounts) && in_array($oid, $locked_accounts)) {
?>
                            <a href='?unlock=1&user_id[]=<?= $oid ?>&nsp=<?= get_nagios_session_protector_id() ?>'><img class='tableItemButton md-action md-button md-400 md-20 md-middle tt-bind' src='<?= theme_image("lock_open.png") ?>' border='0' alt='<?= _("Unlock Account") ?>' title='<?= _("Unlock Account") ?>'></a>
<?php
                        }

                        if (!is_v2_license_type('cloud') || $rs->fields['username'] != 'nagiosadmin') {
?>
                            <a href='?delete=1&amp;user_id[]=<?= $oid ?>&nsp=<?= get_nagios_session_protector_id() ?>'><span class='material-symbols-outlined tableItemButton md-action md-button md-400 md-20 md-middle tt-bind' title='<?= _("Delete") ?>'>delete</span></a>
<?php
                        }
?>
                        </td>
                    </tr>
<?php
                        $rs->MoveNext();
                    }
                }
?>
                </tbody>
            </table>

            <div class="tableFooter">

                <?php table_record_pager($pager_results); ?>

                <div class="tableListMultiOptions btn-icon-and-text pt-2">
                    <div><?= _("With Selected:") ?></div>
                    <button class="tableMultiItemButton tt-bind" title="<?= _('Delete') ?>" value="delete" name="multiButton" type="submit"><span class="material-symbols-outlined tableMultiButton md-action md-button md-400 md-20 md-middle">delete</span></button>
                    <button class="tableMultiItemButton tt-bind" title="<?= _('Send Email') ?>" value="email" name="multiEmailButton" type="button" onclick="users_display_email_selected(false)"><span class="material-symbols-outlined tableMultiButton md-action md-button md-400 md-20 md-middle" title="<?= _("Send Email"); ?>">mail</span></button>
<?php
                if ($locked_accounts !== false) { ?>

                    <button class="tableMultiItemButton tt-bind" title="<?= _('Unlock'); ?>" value="unlock" name="multiButton" type="submit"><span class="material-symbols-outlined tableMultieButton md-action md-button md-400 md-20 md-middle" title="<?= _("Unlock") ?>">lock_open_right</span></button>
<?php
                } ?>
                </div>

            </div>
            <!-- tableFooter -->

        </div>
        <!-- tableContainer -->

    </form>

    <!-- Send email overlay -->
    <script type="text/javascript">

    $(document).ready(function() {
        $('#checkall').click(function() {
            if ($(this).is(':checked')) {
                $('.uidcheckbox').prop('checked', true);
            } else {
                $('.uidcheckbox').prop('checked', false);
            }
        });
    });

    function users_display_email_selected(send_to_all) {
        // Grab the user emails and put them into a variable that will be hidden
        if (!send_to_all) {
            var user_emails = [];
            $('.uidcheckbox:checked').each(function () {
                user_emails.push($(this).data('email'));
            });
            pu_title = "<?= _('Send Email to Selected Users'); ?>";
        } else {
            user_emails = "all";
            pu_title = "<?= _('Send Email to All Users'); ?>";
        }

        // prepare container for graph
        var content = "<div<?= (!is_neptune() ? " style='clear:both;'" : '') ?>>\
                        <h2 style='padding: 0; margin: 0 0 20px 0;'>" + pu_title + "</h2>\
                        <form method='post' id='send_emails_form'>\
                        <table>\
                            <tr>\
                                <td style='padding-right: 10px;'><label><?= _('Email Subject'); ?>:<label></td>\
                                <td><input class='textfield form-control' type='text' value='' name='email_subject' id='email_subject' style='width: 400px;'></td>\
                            </tr>\
                            <tr>\
                                <td style='padding-right: 10px;'><label><?= _('Email Body'); ?>:</label></td>\
                                <td>\
                                    <textarea class='form-control monospace-textarea' style='overflow: auto; width: 534px; height: 150px; margin: 6px 0;  max-width: 558px; max-height: 165px; ' name='email_message' id='email_message'></textarea>\
                                </td>\
                            </tr>\
                            <tr>\
                                <td></td>\
                                <td>\
                                    <button type='submit' class='btn btn-sm btn-primary' name='send_emails' value='1'><?= _('Send Email'); ?></button>\
                                    <span style='margin-left: 10px; color: red;' id='email_error'></span>\
                                </td>\
                            </tr>\
                        </table>\
                        <input type='hidden' value='" + user_emails + "' name='email_user_emails' id='email_user_emails'>\
                        </form>\
                    </div>";

        $("#child_popup_container").height(300);
        $("#child_popup_container").width(650);
        $("#child_popup_layer").height(320);
        $("#child_popup_layer").width(680);
        $("#child_popup_layer").css('position', 'fixed');
        center_child_popup();
        display_child_popup();
        $("#child_popup_layer").css('top', '100px');

        set_child_popup_content(content);

        // Display errors if something is wrong
        $('#send_emails_form').submit(function (e) {

            // Check subject and message
            var subject = $('#email_subject').val();
            var message = $('#email_message').val();
            if (subject == "" || message == "") {
                e.preventDefault();
                $('#email_error').html("<?= _('Must have a subject and message to send email.'); ?>");
                return;
            }

            // Check if there are any checked users
            var users = $('#email_user_emails').val();
            if (users == "") {
                e.preventDefault();
                $('#email_error').html("<?= _('You need to select users to send this email to.'); ?>");
                return;
            }

        });

        $('#close_child_popup_link').click(function () {
            set_child_popup_content('');
            $("#child_popup_layer").css('position', 'absolute');
            $("#child_popup_layer").width(300);
            $("#child_popup_container").width(300);
            center_child_popup();
        });
    }
    </script>

    <?php

    do_page_end(true);
    exit();
}


/**
 * @param bool   $error
 * @param string $msg
 */
function show_edit_user($error = false, $msg = "")
{
    global $request;

    // Dy default we add a new user
    $add = true;

    // Get languages and themes
    $languages = get_languages();
    $authlevels = get_authlevels();
    $number_formats = get_number_formats();
    $date_formats = get_date_formats();
    $week_formats = get_week_formats();

    // Defaults
    $date_format = DF_ISO8601;
    $number_format = NF_2;
    $week_format = WF_US;
    $language = get_option("default_language");
    $add_contact = 0;
    $ccm_access = 0;
    $auth_server_id = 0;
    $autodeploy_access = 0;
    $enable_notifications = '';
    
    // Get options
    $user_id = grab_request_var("user_id", 0);
    if (is_array($user_id)) {
        $user_id = current($user_id);
    }
    if ($user_id != 0) {
        $add = false;
    }

    $username = get_user_attr($user_id, "username");
    $backend_ticket = get_user_attr($user_id, 'backend_ticket');
    $api_key = get_user_attr($user_id, 'api_key');

    if ($error == false) {
        if (isset($request["updated"])) {
            $msg = _("User Updated.");
        } else if (isset($request["added"])) {
            $msg = _("User Added.");
        }

        // Check if this users api key needs updated
        if ($msg === "" && !$add) {
            if ($backend_ticket == $api_key) {
                $msg = sprintf(_("%s API Key hasn't been updated in a while! You should generate a new key for %s."), encode_form_val_minimal($username) . "'s", encode_form_val_minimal($username));
            }
        }
    }

    // Load current user info
    if ($add == false) {

        // Make sure user exists first
        if (!is_valid_user_id($user_id)) {
            flash_message(_("User account was not found.") . " (ID=" . intval($user_id) . ")", FLASH_MSG_ERROR);
            show_users();
        }

        // Make sure we can actually edit this user
        if (is_v2_license_type('cloud') && $username == "nagioscloud") {
            flash_message(_("Must have a valid user ID to be edited."), FLASH_MSG_ERROR);
            show_users();
        }

        // Do not allow username editing for nagiosadmin
        if (!is_v2_license_type('cloud') || $username != "nagiosadmin") {
            $username = grab_request_var("username", get_user_attr($user_id, "username"));
        }

        $email = grab_request_var("email", get_user_attr($user_id, "email"));
        $level = grab_request_var("level", get_user_meta($user_id, "userlevel"));
        $name = grab_request_var("name", get_user_attr($user_id, "name"));
        $enabled = grab_request_var("enabled", get_user_attr($user_id, "enabled"));
        $language = grab_request_var("language", get_user_meta($user_id, "language"));
        $date_format = grab_request_var("defaultDateFormat", intval(get_user_meta($user_id, 'date_format')));
        $number_format = grab_request_var("defaultNumberFormat", intval(get_user_meta($user_id, 'number_format')));
        $week_format = grab_request_var("defaultWeekFormat", intval(get_user_meta($user_id, 'week_format')));
        $phone = grab_request_var("phone", get_user_meta($user_id, "mobile_number"));

        $arr = get_user_nagioscore_contact_info($username);
        $is_nagioscore_contact = grab_array_var($arr, "is_nagioscore_contact", 1);
        if ($is_nagioscore_contact) {
            $enable_notifications = grab_request_var('enable_notifications', intval(get_user_meta($user_id, 'enable_notifications')));
        }

        $api_key = grab_request_var("api_key", get_user_attr($user_id, "api_key"));
        $api_enabled = checkbox_binary(grab_request_var("api_enabled", get_user_attr($user_id, "api_enabled")));
        $insecure_login_enabled = grab_request_var("insecure_login_enabled", get_user_meta($user_id, "insecure_login_enabled", 0));
        $insecure_login_ticket = grab_request_var("insecure_login_ticket", get_user_meta($user_id, "insecure_login_ticket", ""));

        $authorized_for_all_objects = checkbox_binary(grab_request_var("authorized_for_all_objects", get_user_meta($user_id, "authorized_for_all_objects")));
        $authorized_to_configure_objects = checkbox_binary(grab_request_var("authorized_to_configure_objects", get_user_meta($user_id, "authorized_to_configure_objects")));
        $authorized_for_all_object_commands = checkbox_binary(grab_request_var("authorized_for_all_object_commands", get_user_meta($user_id, "authorized_for_all_object_commands")));
        $authorized_for_monitoring_system = checkbox_binary(grab_request_var("authorized_for_monitoring_system", get_user_meta($user_id, "authorized_for_monitoring_system")));
        $advanced_user = checkbox_binary(grab_request_var("advanced_user", get_user_meta($user_id, "advanced_user")));
        $readonly_user = checkbox_binary(grab_request_var("readonly_user", get_user_meta($user_id, "readonly_user")));

        $autodeploy_access = grab_request_var("autodeploy_access", get_user_meta($user_id, "autodeploy_access", 0));
        $ccm_access = grab_request_var("ccm_access", get_user_meta($user_id, "ccm_access", 0));

        $ccm_access_list = grab_request_var("ccm_access_list", array());
        if (empty($ccm_access_list)) {
            $ccm_access_list = get_user_meta($user_id, "ccm_access_list", array());
            if (!empty($ccm_access_list)) {
                $ccm_access_list = unserialize(base64_decode($ccm_access_list));
            }
        }

        $auth_type = grab_request_var("auth_type", get_user_meta($user_id, "auth_type"));
        $auth_server_id = grab_request_var("auth_server_id", get_user_meta($user_id, "auth_server_id"));
        $ldap_ad_username = grab_request_var("ldap_ad_username", get_user_meta($user_id, "ldap_ad_username"));
        $ldap_ad_dn = grab_request_var("ldap_ad_dn", get_user_meta($user_id, "ldap_ad_dn"));
        $allow_local = grab_request_var("allow_local", get_user_meta($user_id, "allow_local", 0));

        // Force nagiosadmin user to use local password no matter what - in case AD/LDAP is unreachable
        if ($user_id == 1) {
            $allow_local = 1;
        }

        $password1 = grab_request_var("password1", "");
        $forcepasswordchange = get_user_meta($user_id, "forcepasswordchange");

        $passwordbox1title = _("New Password");
        $passwordbox2title = _("Repeat New Password");

        $sendemail = "0";
        $sendemailboxtitle = _("Email User New Password");

        $page_title = _("Edit User");
        $page_header = _("Edit User") . ": " . encode_form_val_minimal($username);
        $button_title = _("Update User");
    } else {
        // Get defaults to use for new user (or use submitted data)
        $username = grab_request_var("username", "");
        $email = grab_request_var("email", "");
        $level = grab_request_var("level", "user");
        $name = grab_request_var("name", "");
        $enabled = grab_request_var("enabled", 1);
        $language = grab_request_var("language", $language);
        $date_format = grab_request_var("defaultDateFormat", get_option('default_date_format'));
        $number_format = grab_request_var("defaultNumberFormat", intval(get_option('default_number_format')));
        $week_format = grab_request_var("defaultWeekFormat", intval(get_option('default_week_format')));
        $enable_notifications = grab_request_var('enable_notifications', 1);
        $is_nagioscore_contact = grab_request_var('is_nagioscore_contact', 1);
        $phone = grab_request_var("phone", "");

        $ccm_access_list = grab_request_var("ccm_access_list", array());
        $auth_type = grab_request_var('auth_type', 'local');
        $ldap_ad_username = grab_request_var('ldap_ad_username', '');
        $ldap_ad_dn = grab_request_var('ldap_ad_dn', '');
        $allow_local = grab_request_var('allow_local', 0);

        $add_contact = 1;

        $api_enabled = checkbox_binary(grab_request_var("api_enabled", ""));

        $authorized_for_all_objects = checkbox_binary(grab_request_var("authorized_for_all_objects", ""));
        $authorized_to_configure_objects = checkbox_binary(grab_request_var("authorized_to_configure_objects", ""));
        $authorized_for_all_object_commands = checkbox_binary(grab_request_var("authorized_for_all_object_commands", ""));
        $authorized_for_monitoring_system = checkbox_binary(grab_request_var("authorized_for_monitoring_system", ""));
        $advanced_user = checkbox_binary(grab_request_var("advanced_user", ""));
        $readonly_user = checkbox_binary(grab_request_var("readonly_user", ""));

        $password1 = random_string(16);
        $forcepasswordchange = "1";
        $passwordbox1title = _("Password");
        $passwordbox2title = _("Repeat Password");

        $sendemail = "1";
        $sendemailboxtitle = _("Email User Account Information");

        $page_title = _("Add New User");
        $page_header = _("Add New User");
        $button_title = _("Add User");
    }

    // For use with all of the callbacks
    $user_html_cbargs = array(
        'user_id'           => $user_id,
        'add'               => $add,
        'session_user_id'   => $_SESSION['user_id'],
    );

    do_page_start(array("page_title" => $page_title), true);
    ?>

    <h1><?= $page_header; ?></h1>

    <?php
    display_message($error, false, $msg);
    ?>

    <script type="text/javascript">
    $(document).ready(function() {

        var updateButtonClicked = false;
        var toggle_ccm_access = function() {
            var access = $('#ccm_access option:selected').val();
            if (access == 2) {
                $('.ccm-limited-access-settings').show();
            } else {
                $('.ccm-limited-access-settings').hide();
            }
        }

        if ($('#addContactBox').length === 1 && $('#addContactBox').is(":checked") === false) {
            $('#notificationsBox').prop('disabled', true);
        }

        $('#addContactBox').change(function() {
            if ($(this).is(":checked")) {
                $('#notificationsBox').prop('disabled', false);
            } else {
                $('#notificationsBox').prop('disabled', true);
            }
        });

        $('#updateButton').click(function() {
            updateButtonClicked = true;
        })

        $('#ccm_access').change(function() {
            toggle_ccm_access();
        });

        $('.toggle-all').click(function() {
            $('input[name="ccm_access_list[]"]').prop('checked', true);
        });

        $('.toggle-none').click(function() {
            $('input[name="ccm_access_list[]"]').prop('checked', false);
        });

        $('input[name=password1]').keyup(function() {
            if ($(this).val() !== "") {
                if ($('input[name=sendemail]').is(':disabled')) {
                    $('input[name=sendemail]').prop('disabled', false);
                }
            } else {
                $('input[name=sendemail]').prop('disabled', true);
                $('input[name=sendemail]').prop('checked', false);
            }
        });

        $('.set-random-pass').click(function() {
            var newpass = Array(16).fill("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz").map(function(x) { return x[Math.floor(Math.random() * x.length)] }).join('');
            $('input[name=password1]').val(newpass);
            $('input[name=sendemail]').prop('disabled', false).prop('checked', true);
        });

        <?php if ($add == false) { ?>
        $('#updateForm').submit(function(e) {
            if (updateButtonClicked && $('#usernameBox').val() != "<?= encode_form_val_minimal($username); ?>") {
                var go_ahead_and_change = confirm("<?= _('Changing your username is not recommended. But if you wish to proceed, you should be warned that it may take a while to take effect depending on your configuration. Do you wish to proceed?'); ?>");
                if (!go_ahead_and_change) {
                    e.preventDefault();
                }
            }
        });
        <?php } ?>
    });
    </script>

    <form id="updateForm" method="post" action="">

        <input type="hidden" name="update" value="1">
        <?= get_nagios_session_protector(); ?>
        <input type="hidden" name="users" value="1">
        <input type="hidden" name="user_id[]" value="<?= encode_form_val($user_id); ?>">

        <div class="container-fluid">
            <div class="row">

                <div class="col-lg-6 col-xl-4">
<?php
    if (!is_neptune()) {
?>
            <h5 class="ul"><?= _("Account Settings"); ?></h5>

            <table class="editDataSourceTable table table-condensed table-no-border" cellpadding="2">
                <tr>
                    <td>
                        <label for="usernameBox"><?= _("Username") ?>:</label>
                    </td>
                    <td>
                        <input type="text" style="width: 240px;" name="username" id="usernameBox" value="<?= encode_form_val_minimal($username); ?>" <?php if ($username == "nagiosadmin") { echo "readonly"; } ?> class="textfield form-control">
                    </td>
                </tr>
                <tr class="pw">
                    <td>
                        <label for="passwordBox1"><?= $passwordbox1title; ?>:</label>
                    </td>
                    <td>
                        <input type="password" style="width: 160px;"  name="password1" id="passwordBox1" value="<?= encode_form_val($password1); ?>" class="textfield form-control" <?= sensitive_field_autocomplete(); ?>>
                        <button type="button" style="vertical-align: top;" class="btn btn-sm btn-default tt-bind btn-show-password" title="<?= _("Show password"); ?>"><i class="material-symbols-outlined md-20">Visibility</i></button>
                    </td>
                </tr>
                <tr class="pw">
                    <td> 
                        <label for="sendEmailBox"><?= $sendemailboxtitle; ?>:</label>
                        <div class="material-symbols-outlined tt-bind md-20" tabindex="99" title="<?= _('You must fill out a new password or click set to a random secure password before setting this option.'); ?>">help</div>
                    </td>
                    <td style="line-height: 18px; vertical-align: middle;">
                        <input type="checkbox" id="sendEmailBox" name="sendemail" style="margin: 0; vertical-align: text-top;" <?= (empty($password1) ? 'disabled' : '') ?> <?= is_checked($sendemail, "1"); ?>> &nbsp;
                        <a class="set-random-pass"><?= _('Set to a random secure password'); ?></a>
                    </td>
                </tr>
                <tr class="lo">
                    <td>
                        <label for="forcePasswordChangeBox"><?= _("Force Password Change at Next Login"); ?>:</label>
                    </td>
                    <td>
                        <input type="checkbox" class="checkbox" id="forcePasswordChangeBox" name="forcepasswordchange" <?= is_checked($forcepasswordchange, "1") ?>>
                    </td>
                </tr>
            </table>
<?php
    } else {
        echo neptune_heading(_("Account Settings"));

        echo neptune_text(_("Username"), 'usernameBox', 'username', encode_form_val_minimal($username), '', '', 'form-item-required', ($username == "nagiosadmin" ? "readonly" : "" ));
?>
        <div class="pw">
<?php
        echo neptune_password($passwordbox1title, 'passwordBox1', 'password1', encode_form_val($password1), '', sensitive_field_autocomplete(), '', 'form-item-required');
?>
                    <div class="btn-icon-and-text">
<?php
        echo neptune_centered_checkbox($sendemailboxtitle, 'sendEmailBox', 'sendemail', $sendemail, "1", empty($password1));
?>
                        <div class="material-symbols-outlined tt-bind md-20" tabindex="99" title="<?= _('You must fill out a new password or click set to a random secure password before setting this option.') ?>">help</div>
                        <a class="set-random-pass text-medium"><?= _('Set to a random secure password') ?></a>
                    </div>
<?php
        echo neptune_centered_checkbox(_('Force password change at next login'), 'forcePasswordChangeBox', 'forcepasswordchange', $forcepasswordchange, 1);
?>
        </div>
<?php
    }

    if (!is_neptune()) {
?>
            <h5 class="ul"><?= _("General Settings"); ?></h5>

            <table class="editDataSourceTable table table-condensed table-no-border" cellpadding="2">
                <tr>
                    <td>
                        <label for="nameBox"><?= _("Alias (Name)"); ?>:</label>
                    </td>
                    <td>
                        <input type="text" size="30" name="name" id="nameBox" value="<?= encode_form_val($name); ?>" class="textfield form-control">
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="emailAddressBox"><?= _("Email Address"); ?>:</label>
                    </td>
                    <td>
                        <input type="text" size="30" name="email" id="emailAddressBox" value="<?= encode_form_val($email); ?>" class="textfield form-control">
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="phone-number"><?= _("Phone Number"); ?>:</label>
                    </td>
                    <td class="form-inline">
                        <div class="input-group" style="width: 150px;">
                            <input type="text" name="phone" id="phone-number" value="<?= encode_form_val($phone); ?>" class="form-control">
                            <?php if (!empty($user_id)) { ?>
                            <label class="input-group-addon" style="padding: 6px 8px;"><?= get_user_phone_vtag($user_id); ?></label>
                            <?php } ?>
                        </div>
                    </td>
                </tr>
                <?php
                if ($add == true || !$is_nagioscore_contact) {
                ?>
                <tr>
                    <td>
                        <label for="addContactBox"><?= _("Create as Monitoring Contact"); ?>:</label>
                    </td>
                    <td>
                        <input type="checkbox" class="checkbox" id="addContactBox" name="add_contact" <?= is_checked($add_contact, 1); ?>>
                    </td>
                </tr>
                <?php
                }

                ?>
                <tr>
                    <td>
                        <label for="notificationsBox"><?= _('Enable Notifications'); ?>:</label>
                    </td>
                    <td>
                        <input type="checkbox" class="checkbox" id="notificationsBox" name="enable_notifications" <?= is_checked($enable_notifications, 1); ?>>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="addContactBox"><?= _("Account Enabled"); ?>:</label>
                    </td>
                    <td>
                        <input type="checkbox" class="checkbox" id="enableUserBox" name="enabled" <?= is_checked($enabled, 1); ?>>
                    </td>
                </tr>
                <?php
                    
                    // Let components add html to the edit/add user page (inside of the general settings table)
                    do_callbacks(CALLBACK_USER_EDIT_HTML_GENERAL_SETTINGS, $user_html_cbargs);

                ?>
        </table>
<?php
    } else {
        echo neptune_section_spacer();
        echo neptune_heading(_("General Settings"));

        echo neptune_text(_("Alias (Name)"), 'nameBox', 'name', encode_form_val($name), '', '', 'form-item-required');

        echo neptune_text(_("Email Address"), 'emailAddressBox', 'email', encode_form_val($email), '', '', 'form-item-required');

        echo neptune_text_input_group(_("Phone Number"), 'phone-number', 'phone', encode_form_val($phone), '', (!empty($user_id) ? get_user_phone_vtag($user_id) : ''));

        if ($add == true || !$is_nagioscore_contact) {
            echo neptune_centered_checkbox(_("Create as Monitoring Contact"), 'addContactBox', 'add_contact', $add_contact, 1);
        }

        echo neptune_centered_checkbox(_("Enable Notifications"), 'notificationsBox', 'enable_notifications', $enable_notifications, 1);

        echo neptune_centered_checkbox(_("Account Enabled"), 'enableUserBox', 'enabled', $enabled, 1);

        // Let components add html to the edit/add user page (inside of the general settings table)
        do_callbacks(CALLBACK_USER_EDIT_HTML_GENERAL_SETTINGS, $user_html_cbargs);
    }

    if (!is_neptune()) {
?>
        <h5 class="ul"><?= _("Preferences"); ?></h5>

        <table class="editDataSourceTable table table-condensed table-no-border" cellpadding="2">

            <tr>
                <td>
                    <label for="languageListForm"><?= _("Language"); ?>:</label>
                </td>
                <td>
                    <select name="language" id="languageListForm" class="languageListForm dropdown form-control">
<?php
        foreach ($languages as $lang => $title) {
?>
                        <option value="<?= $lang; ?>" <?= is_selected($language, $lang); ?>><?= get_language_nicename($title); ?></option>
<?php
        }
?>
                    </select>
                </td>
            </tr>

            <tr>
                <td>
                    <label for="defaultDateFormat"><?= _("Date Format"); ?>:</label>
                </td>
                <td>
                    <select name="defaultDateFormat" class="dateformatList dropdown form-control">
<?php
        foreach ($date_formats as $id => $txt) {
?>
                        <option value="<?= $id; ?>" <?= is_selected($id, $date_format); ?>><?= $txt; ?></option>
<?php
        }
?>
                    </select>
                </td>
            </tr>

            <tr>
                <td>
                    <label for="defaultNumberFormat"><?= _("Number Format"); ?>:</label>
                </td>
                <td>
                    <select name="defaultNumberFormat" class="numberformatList dropdown form-control">
<?php
        foreach ($number_formats as $id => $txt) {
?>
                        <option value="<?= $id; ?>" <?= is_selected($id, $number_format); ?>><?= $txt; ?></option>
<?php
        }
?>
                    </select>
                </td>
            </tr>

            <tr>
                <td>
                    <label for="defaultWeekFormat"><?= _("Week Format"); ?>:</label>
                </td>
                <td>
                    <select name="defaultWeekFormat" class="weekformatList dropdown form-control">
<?php
        foreach ($week_formats as $id => $txt) {
?>
                        <option value="<?= $id; ?>" <?= is_selected($id, $week_format); ?>><?= $txt; ?></option>
<?php
        }
?>
                    </select>
                </td>
            </tr>
<?php
        // Let components add html to the edit/add user page (inside of the preferences table)
        do_callbacks(CALLBACK_USER_EDIT_HTML_PREFERENCES, $user_html_cbargs);
?>
        </table>
<?php
    } else {

        echo neptune_section_spacer();
        echo neptune_heading(_("Preferences"));

        $language_options = array ();

        foreach ($languages as $lang => $title) {
            $language_options[$lang] = get_language_nicename($title);
        }

        echo neptune_select(_("Language"), 'languageListForm', 'language', $language_options, $language, '', 'languageListForm');

        echo neptune_select(_("Date Format"), 'defaultDateFormat', 'defaultDateFormat', $date_formats, $date_format, '', 'dateformatList');

        echo neptune_select(_("Number Format"), 'defaultNumberFormat', 'defaultNumberFormat', $number_formats, $number_format, '', 'numberformatList');

        echo neptune_select(_("Week Format"), 'defaultWeekFormat', 'defaultWeekFormat', $week_formats, $week_format, '', 'weekformatList');

        // Let components add html to the edit/add user page (inside of the preferences table)
        do_callbacks(CALLBACK_USER_EDIT_HTML_PREFERENCES, $user_html_cbargs);
    }

    if (!is_neptune()) {
?>
        <h5 class="ul"><?= _("Authentication Settings"); ?> <div tabindex="10" class="material-symbols-outlined pop md-20" data-content="<?= _('User accounts can be authenticated in many different ways either from your local database or external programs such as Active Directory or LDAP. You can set up external authentication servers in the'); ?> <a href='<?= get_component_url_base('ldap_ad_integration').'/manage.php'; ?>'><?= _('LDAP/AD Integration'); ?></a> <?= _('settings'); ?>.">help</div></h5>

            <?php
            // Grab LDAP/AD servers
            $ad = array();
            $ldap = array();
            $servers_raw = get_option("ldap_ad_integration_component_servers");
            if ($servers_raw == "") { $servers = array(); } else {
                $servers = unserialize(base64_decode($servers_raw));
            }
            foreach ($servers as $server) {
                if ($server['conn_method'] == 'ldap') {
                    $ldap[] = $server;
                } else if ($server['conn_method'] == 'ad') {
                    $ad[] = $server;
                }
            }
            ?>

            <table class="table table-condensed table-no-border">
                <tbody>
                    <tr>
                        <td style="width: 110px;"><label><?= _("Auth Type"); ?>:</label></td>
                        <td>
                            <select name="auth_type" id="auth_type" class="form-control">
                                <option value="local" <?= is_selected($auth_type, "local"); ?>><?= _("Local (Default)"); ?></option>
                                <option value="ad" <?= is_selected($auth_type, "ad"); ?>><?= _("Active Directory"); ?></option>
                                <option value="ldap" <?= is_selected($auth_type, "ldap"); ?>><?= _("LDAP"); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="hide auth-ad">
                        <td><label><?= _("AD Server"); ?>:</label></td>
                        <td>
                            <select name="ad_server" class="form-control">
                                <?php foreach ($ad as $s) { ?>
                                <option value="<?= $s['id']; ?>" <?= is_selected($auth_server_id, $s['id']); ?>><?= encode_form_val($s['ad_domain_controllers']); if (!$s['enabled']) { echo _('(Disabled)'); } ?></option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="hide auth-ad">
                        <td><label><?= _("AD Username"); ?>:</label></td>
                        <td>
                            <input type="text" name="ad_username" style="width: 240px;" class="form-control" value="<?= encode_form_val($ldap_ad_username); ?>">
                        </td>
                    </tr>
                    <tr class="hide auth-ldap">
                        <td><label><?= _("LDAP Server"); ?>:</label></td>
                        <td>
                            <select name="ldap_server" class="form-control">
                                <?php foreach ($ldap as $s) { ?>
                                <option value="<?= $s['id']; ?>" <?= is_selected($auth_server_id, $s['id']); ?>><?= encode_form_val($s['ldap_host']); if (!$s['enabled']) { echo _('(Disabled)'); } ?></option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="hide auth-ldap">
                        <td><label><?= _("User's Full DN"); ?>:</label></td>
                        <td>
                            <input type="text" style="width: 400px;" class="form-control" name="dn" value="<?= encode_form_val($ldap_ad_dn); ?>" placeholder="cn=John Smith,dn=nagios,dc=com">
                        </td>
                    </tr>
                    <tr class="hide auth-ldap auth-ad">
                        <td></td>
                        <td>
                            <div class="checkbox">
                                <label>
                                    <input class="checkbox" name="allow_local" id="allow_local" value="1" type="checkbox" <?php if ($user_id == 1) { echo 'disabled'; } ?> <?= is_checked($allow_local, 1); ?>> <?= _("Allow local login if auth server login fails"); ?>
                                </label>
                                <div class="material-symbols-outlined pop md-20" tabindex="11" style="font-size: 13px; line-height: 20px; vertical-align: middle;" data-placement="top" data-content="<?= _('By checking this box you will allow the user to use the local password created for this user (if the password is not blank) when the auth server cannot be connected to, times out, or the password provided is incorrect. This allows a secondary means of authentication in case the auth server is unreachable.'); ?>">help</div>
                            </div>
                        </td>
                    </tr>
<?php
        // Let components add html to the edit/add user page (inside the authentication settings table)
        do_callbacks(CALLBACK_USER_EDIT_HTML_AUTH_SETTINGS, $user_html_cbargs);
?>
                </tbody>
            </table>

            </div>
<?php
    } else {
        echo neptune_section_spacer();
        echo neptune_heading(
            _("Authentication Settings")." <i tabindex=\"10\" class=\"material-symbols-outlined pop md-20\" data-content=\""._('User accounts can be authenticated in many different ways either from your local database or external programs such as Active Directory or LDAP. You can set up external authentication servers in the').
            " <a href='".get_component_url_base('ldap_ad_integration')."/manage.php'>"._('LDAP/AD Integration')."</a> "._('settings')."\">help</i>");

        // Grab LDAP/AD servers
        $ad = array();
        $ldap = array();
        $servers_raw = get_option("ldap_ad_integration_component_servers");

        if ($servers_raw == "") {
            $servers = array();
        } else {
            $servers = unserialize(base64_decode($servers_raw));
        }

        foreach ($servers as $server) {
            if ($server['conn_method'] == 'ldap') {
                $ldap[] = $server;
            } else if ($server['conn_method'] == 'ad') {
                $ad[] = $server;
            }
        }

        $auth_type_options = array (
                "local" => _('Local (Default)'),
                "ad"    => _('Active Directory'),
                "ldap"  => _('LDAP'),
            );

        echo neptune_select(_("Auth Type"), 'auth_type', 'auth_type', $auth_type_options, $auth_type);
?>
                <div class="hide auth-ad">
<?php
        $auth_server_options = array();

        foreach ($ad as $server) {
            $auth_server_options[$server['id']] = $server['ad_domain_controllers'].(!$server['enabled'] ? _(' (Disabled)') : '');
        }

        echo neptune_select(_("AD Server"), 'ad_server', 'ad_server', $auth_server_options, $auth_server_id, '', '');
?>
                </div>
                <div class="hide auth-ad">
<?php
        echo neptune_text(_("AD Username"), 'ad_username', 'ad_username', encode_form_val($ldap_ad_username));
?>
                </div>
                <div class="hide auth-ldap">
<?php
        $ldap_server_options = array();

        foreach ($ldap as $server) {
            $ldap_server_options[$server['id']] = $server['ldap_host'].(!$server['enabled'] ? _(' (Disabled)') : '');
        }

        echo neptune_select(_("LDAP Server"), 'ldap_server', 'ldap_server', $ldap_server_options, $auth_server_id, '', '');
?>
                </div>
                <div class="hide auth-ldap">
<?php
        echo neptune_text(_("User's Full DN"), 'dn', 'dn', encode_form_val($ldap_ad_dn), '', '', 'placeholder="cn=John Smith,dn=nagios,dc=com"');
?>
                </div>
                <div class="hide auth-ldap auth-ad">
<?php
        $title = _("Allow local login if auth server login fails").'<div class="material-symbols-outlined pop md-20" tabindex="11" style="font-size: 13px; line-height: 20px; vertical-align: middle;" data-placement="top" data-content="'._('By checking this box you will allow the user to use the local password created for this user (if the password is not blank) when the auth server cannot be connected to, times out, or the password provided is incorrect. This allows a secondary means of authentication in case the auth server is unreachable.').'">help</div>';

        echo neptune_centered_checkbox($title, 'allow_local', 'allow_local', $allow_local, 1, $user_id == 1);
?>
                </div>
<?php
        // Let components add html to the edit/add user page (inside the authentication settings table)
        do_callbacks(CALLBACK_USER_EDIT_HTML_AUTH_SETTINGS, $user_html_cbargs);
?>
            </div>
<?php
    }

    if (!is_neptune()) {
?>
            <div class="col-lg-6 col-xl-4">

        <h5 class="ul"><?= _('Security Settings'); ?></h5>

        <table class="editDataSourceTable ss-table table table-condensed table-no-border" cellpadding="2">

            <tr>
                <td>
                    <label for="authLevelListForm"><?= _('Authorization Level'); ?>:</label>
                    <div tabindex="1" class="material-symbols-outlined pop md-20" data-content="<b><?= _('User'); ?></b> - <?= _('Can only see hosts and services they are a contact of by default. View only, no configuration access by defualt.'); ?><br><br><b><?= _('Admin'); ?></b> - <?= sprintf(_('Access to all objects by default and can control, access, and configure the entire %s system and has access to the admin section.'), get_product_name()); ?>">help</div>
                </td>
                <td>
                    <select name="level" id="authLevelListForm" class="authLevelList dropdown form-control">
                        <?php foreach ($authlevels as $al => $at) { ?>
                        <option value="<?= $al; ?>" <?= is_selected($level, $al); ?>><?= $at . "</option>"; ?>
                        <?php } ?>
                    </select>
                </td>
            </tr>

            <tr class="user-setting"><td></td><td></td></tr>

            <tr class="user-setting">
                <td>
                    <label for="aoo"><?= _('Can see all hosts and services'); ?>:</label>
                    <div tabindex="2" class="material-symbols-outlined pop md-20" data-content="<?= _('Allows a user to view all host and services that are applied in the system.'); ?><br><br><i><?= _('Note: User does not need to be a contact of an object to see it with this enabled.'); ?></i>">help</div>
                </td>
                <td>
                    <input type="checkbox" class="checkbox" id="aoo" name="authorized_for_all_objects" <?= is_checked_admin($authorized_for_all_objects, 1, $level); ?>>
                </td>
            </tr>

            <tr class="user-setting">
                <td>
                    <label for="aaoc"><?= _('Can control all hosts and services'); ?>:</label>
                    <div tabindex="3" class="material-symbols-outlined pop md-20" data-content="<?= _('Allows the user run commands on all hosts and services. These are commands that can be ran on the Nagios command file.'); ?><br><br><i><?= _('Note: Without this option users are still allowed to run commands on hosts and services they are contacts of. This is normally used if you have selected the'); ?> <b><?= _('Can see all hosts and services'); ?></b> <?= _('above'); ?>.</i>">help</div>
                </td>
                <td>
                    <input type="checkbox" class="checkbox" id="aaoc" name="authorized_for_all_object_commands" <?= is_checked_admin($authorized_for_all_object_commands, 1, $level); ?>>
                </td>
            </tr>

            <tr class="user-setting">
                <td>
                    <label for="aco"><?= _('Can configure hosts and services'); ?>:</label>
                    <div tabindex="4" class="material-symbols-outlined pop md-20" data-content="<?= _('Allows a user to be able to access the following for hosts and services:'); ?><ul style='margin: 0; padding: 10px 0 5px 20px;'><li><?= _('Run Configuration Wizards'); ?></li><li><?= _('Delete from detail page'); ?></li><li><?= _('Re-configure from detail page'); ?></li</ul>">help</div>
                </td>
                <td>
                    <input type="checkbox" class="checkbox" id="aco" name="authorized_to_configure_objects" <?= is_checked_admin($authorized_to_configure_objects, 1, $level); ?>>
                </td>
            </tr>

            <tr class="user-setting">
                <td>
                    <label for="au"><?= _('Can access advanced features'); ?>:</label>
                    <div tabindex="5" class="material-symbols-outlined pop md-20" data-content="<?= _('Allows the editing of check command in the re-configure host/service page.<br><br>Shows advanced tab and commands in host/service detail pages.<br><br>Allows setting host parents during wizards and re-configure on host detail page.'); ?>">help</div>
                </td>
                <td>
                    <input type="checkbox" class="checkbox" id="au" name="advanced_user" <?= is_checked_admin($advanced_user, 1, $level); ?>>
                </td>
            </tr>

            <tr class="user-setting">
                <td>
                    <label for="ams"><?= _('Can access monitoring engine'); ?>:</label>
                    <div tabindex="6" class="material-symbols-outlined pop md-20" data-content="<?= _('Allows access to the Monitoring Process section in the main page.'); ?><br><br><?= _('Allows managing Nagios Core process such as starting, stopping, restarting, and changing process settings.'); ?><br><br><?= _('Allows access to the Event Log.'); ?>">help</div>
                </td>
                <td>
                    <input type="checkbox" class="checkbox" id="ams" name="authorized_for_monitoring_system" <?= is_checked_admin($authorized_for_monitoring_system, 1, $level); ?>>
                </td>
            </tr>

            <tr><td></td><td></td></tr>

            <tr class="user-setting">
                <td>
                    <label for="rou"><?= _('Read-only access'); ?>:</label>
                    <div tabindex="7" class="material-symbols-outlined pop md-20" data-content="<?= _('Restrict the user to have read-only access.'); ?><br><br><i><?= _('Note: This overwrites the following'); ?>:<ul style='margin: 0; padding: 10px 0 5px 20px;'><li><?= _('Can control hosts and services'); ?></li><li><?= _('Can configure hosts and services'); ?></li><li><?= _('Can access advanced features'); ?></li></ul></i>">help</div>
                </td>
                <td>
                    <input type="checkbox" class="checkbox" id="rou" name="readonly_user" <?= is_checked_admin($readonly_user, 1, 0); ?>>
                </td>
            </tr>

            <tr class="user-setting">
                <td>
                    <label for="api_enabled"><?= _("API access"); ?>:</label>
                    <div tabindex="8" class="material-symbols-outlined pop md-20" data-content="<?= _('Allow access to use the API and integrated help documentation.'); ?><br><br><i><?= _('Note: Users can only access the objects API endpoint.'); ?></i>">help</div>
                </td>
                <td>
                    <input type="checkbox" class="checkbox" id="api_enabled" name="api_enabled" <?= is_checked($api_enabled, 1); ?>>
                </td>
            </tr>

            <tr class="user-setting"><td></td><td></td></tr>

            <tr class="user-setting">
                <td>
                    <label for="autodeploy_access"><?= _("Auto deploy access"); ?>:</label>
                    <div tabindex="13" class="material-symbols-outlined pop md-20" data-content="<?= _('Allow access to use the Auto Deployment component.'); ?>">help</div>
                </td>
                <td>
                    <input type="checkbox" class="checkbox" id="autodeploy_access" name="autodeploy_access" value="1" <?= is_checked($autodeploy_access, 1); ?>>
                </td>
            </tr>

            <tr class="user-setting">
                <td>
                    <label for="ccm_access"><?= _("Core Config Manager access:"); ?></label>
                    <div tabindex="9" class="material-symbols-outlined pop md-20" data-content="<?= _('Allow user to view and access the CCM.'); ?><br><br><b><?= _('None'); ?></b> - <?= _('Cannot use or view the CCM.'); ?><br><br><b><?= _('Login'); ?></b> - <?= _('Can view CCM links and must log in with a CCM user account.'); ?><br><br><b><?= _('Limited'); ?></b> - <?= _('Integrated CCM access. User can only access the objects they can view in the interface normally. Allows for setting specific permissions for the user.'); ?><br><br><b><?= _('Full'); ?></b> - <?= _('Integrated CCM access. Can access all objects with no admin features.'); ?>">help</div>
                </td>
                <td>
                    <select id="ccm_access" name="ccm_access" class="form-control">
                        <option value="0" <?php if ($ccm_access == 0) { echo 'selected'; } ?>><?= _("None"); ?></option>
                        <option value="1" <?php if ($ccm_access == 1) { echo 'selected'; } ?>><?= _("Login"); ?></option>
                        <option value="2" <?php if ($ccm_access == 2) { echo 'selected'; } ?>><?= _("Limited"); ?></option>
                        <option value="3" <?php if ($ccm_access == 3) { echo 'selected'; } ?>><?= _("Full"); ?></option>
                    </select>
                </td>
            </tr>

            <tr class="user-setting">
                <td colspan="2" class="ccm-limited-access-settings<?php if ($ccm_access != 2 || is_admin($user_id)) { echo ' hide'; } ?>">
                    <div class="well" style="margin: 0;">

                        <div style="position: relative; padding-bottom: 10px;"><b><?= _('Limited Access CCM Permissions'); ?></b> <div style="position: absolute; right: 0; top: 0;"><?= _('Toggle'); ?> <a class="toggle-all"><?= _('All') ?></a> / <a class="toggle-none"><?= _('None'); ?></a></div></div>
                        <p>
                            <?= _('Users can only VIEW the below object types.'); ?><br>
                            <?= _('Select the object types to give them access to ADD, REMOVE, and EDIT.'); ?>
                        </p>

                        <table class="table table-condensed table-no-border table-bordered">
                            <thead style="border-bottom: 1px solid #EEE;">
                                <tr>
                                    <th colspan="3"><?= _("Group Permissions"); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="width: 33%;">
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="hostgroup"<?php if (in_array('hostgroup', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Host Groups'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td style="width: 33%;">
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="servicegroup"<?php if (in_array('servicegroup', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Service Groups'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td style="width: 33%;"></td>
                                </tr>
                            </tbody>
                            <thead style="border-bottom: 1px solid #EEE;">
                                <tr>
                                    <th colspan="3"><?= _("Alerting Permissions"); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="contact"<?php if (in_array('contact', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Contacts'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="contactgroup"<?php if (in_array('contactgroup', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Contact Groups'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="timeperiod"<?php if (in_array('timeperiod', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Time Periods'); ?>
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="hostescalation"<?php if (in_array('hostescalation', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Host Escalations'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="serviceescalation"<?php if (in_array('serviceescalation', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Service Escalations'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td></td>
                                </tr>
                            </tbody>
                            <thead style="border-bottom: 1px solid #EEE; border-top: 1px solid #EEE;">
                                <tr>
                                    <th colspan="3"><?= _("Template Permissions"); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="hosttemplate"<?php if (in_array('hosttemplate', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Host Templates'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="servicetemplate"<?php if (in_array('servicetemplate', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Service Templates'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="contacttemplate"<?php if (in_array('contacttemplate', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Contact Templates'); ?>
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                            <thead style="border-bottom: 1px solid #EEE; border-top: 1px solid #EEE;">
                                <tr>
                                    <th colspan="3"><?= _("Command Permissions"); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="command"<?php if (in_array('command', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Commands'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            </tbody>
                            <thead style="border-bottom: 1px solid #EEE; border-top: 1px solid #EEE;">
                                <tr>
                                    <th colspan="3"><?= _("Advanced Permissions"); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="hostdependency"<?php if (in_array('hostdependency', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Host Dependencies'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td colspan="2">
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="servicedependency"<?php if (in_array('servicedependency', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Service Dependencies'); ?>
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p style="margin-top: 10px;"><?= _("Tools are only available if assigned below."); ?></p>
                        <table class="table table-condensed table-no-border table-bordered">
                            <thead style="border-bottom: 1px solid #EEE;">
                                <tr>
                                    <th colspan="3"><?= _("Tool Permissions"); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="staticconfig"<?php if (in_array('staticconfig', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Static Config Editor'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="usermacros"<?php if (in_array('usermacros', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('User Macros'); ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="import"<?php if (in_array('import', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Import Config Files'); ?>
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="3">
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="ccm_access_list[]" value="configmanagement"<?php if (in_array('configmanagement', $ccm_access_list)) { echo ' checked'; } ?>> <?= _('Config File Management'); ?>
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                    </div>
                </td>
            </tr>

        </table>

            </div>
<?php
    } else {
?>
            <div class="col-lg-6 col-xl-4">
<?php
        echo neptune_heading(_("Security Settings"));

        $title = _("Authorization Level").'<div tabindex="1" class="material-symbols-outlined pop md-20" data-content="<b>'._('User').'</b> - '._('Can only see hosts and services they are a contact of by default. View only, no configuration access by defualt.').'<br><br><b>'._('Admin').'</b> - '.sprintf(_('Access to all objects by default and can control, access, and configure the entire %s system and has access to the admin section.'), get_product_name()).'">help</div>';

        echo neptune_select($title, 'authLevelListForm', 'level', $authlevels, $level, '', 'authLevelList');

        $tooltip = '<div tabindex="2" class="material-symbols-outlined pop md-20" data-content="'._('Allows a user to view all host and services that are applied in the system.').'<br><br><i>'._('Note: User does not need to be a contact of an object to see it with this enabled.').'</i>">help</div>';

        echo "<div class='user-setting'>" . neptune_centered_checkbox(_('Can see all hosts and services'), 'aoo', 'authorized_for_all_objects', admin_checkbox_binary($authorized_for_all_objects, $level), 1, false, $tooltip) . "</div>";

        $tooltip = '<div tabindex="3" class="material-symbols-outlined pop md-20" data-content="'._('Allows the user run commands on all hosts and services. These are commands that can be ran on the Nagios command file.').'<br><br><i>'._('Note: Without this option users are still allowed to run commands on hosts and services they are contacts of. This is normally used if you have selected the').' <b>'._('Can see all hosts and services').'</b> '._('above').'.</i>">help</div>';

        echo "<div class='user-setting'>" . neptune_centered_checkbox(_('Can control all hosts and services'), 'aaoc', 'authorized_for_all_object_commands', admin_checkbox_binary($authorized_for_all_object_commands, $level), 1, false, $tooltip) . "</div>";

        $tooltip = '<div tabindex="4" class="material-symbols-outlined pop md-20" data-content="'._('Allows a user to be able to access the following for hosts and services:').'<ul style=\'margin: 0; padding: 10px 0 5px 20px;\'><li>'._('Run Configuration Wizards').'</li><li>'._('Delete from detail page').'</li><li>'._('Re-configure from detail page').'</li></ul>">help</div>';

        echo "<div class='user-setting'>" . neptune_centered_checkbox(_('Can configure hosts and services'), 'aco', 'authorized_to_configure_objects', admin_checkbox_binary($authorized_to_configure_objects, $level), 1, false, $tooltip) . "</div>";

        $tooltip = '<div tabindex="5" class="material-symbols-outlined pop md-20" data-content="'._('Allows the editing of check command in the re-configure host/service page.<br><br>Shows advanced tab and commands in host/service detail pages.<br><br>Allows setting host parents during wizards and re-configure on host detail page.').'">help</div>';

        echo "<div class='user-setting'>" . neptune_centered_checkbox(_('Can access advanced features'), 'au', 'advanced_user', admin_checkbox_binary($advanced_user, $level), 1, false, $tooltip) . "</div>";

        $tooltip = '<div tabindex="6" class="material-symbols-outlined pop md-20" data-content="'._('Allows access to the Monitoring Process section in the main page.').'<br><br>'._('Allows managing Nagios Core process such as starting, stopping, restarting, and changing process settings.').'<br><br>'._('Allows access to the Event Log.').'">help</div>';

        echo "<div class='user-setting'>" . neptune_centered_checkbox(_('Can access monitoring engine'), 'ams', 'authorized_for_monitoring_system', admin_checkbox_binary($authorized_for_monitoring_system, $level), 1, false, $tooltip) . "</div>";

        $tooltip = '<div tabindex="7" class="material-symbols-outlined pop md-20" data-content="'._('Restrict the user to have read-only access.').'<br><br><i>'._('Note: This overwrites the following').':<ul style=\'margin: 0; padding: 10px 0 5px 20px;\'><li>'._('Can control hosts and services').'</li><li>'._('Can configure hosts and services').'</li><li>'._('Can access advanced features').'</li></ul></i>">help</div>';

        echo "<div class='user-setting'>" . neptune_centered_checkbox(_('Read-only eccess'), 'rou', 'readonly_user', admin_checkbox_binary($readonly_user, 0), 1, false, $tooltip) . "</div>";

        $tooltip = '<div tabindex="8" class="material-symbols-outlined pop md-20" data-content="'._('Allow access to use the API and integrated help documentation.').'<br><br><i>'._('Note: Users can only access the objects API endpoint.').'</i>">help</div>';

        echo "<div class='user-setting'>" . neptune_centered_checkbox(_('API access'), 'api_enabled', 'api_enabled', $api_enabled, 1, false, $tooltip) . "</div>";

        $tooltip = '<div tabindex="13" class="material-symbols-outlined pop md-20" data-content="'._('Allow access to use the Auto Deployment component.').'">help</div>';

        echo "<div class='user-setting'>" . neptune_centered_checkbox(_('Auto deploy access'), 'autodeploy_access', 'autodeploy_access', $autodeploy_access, 1, false, $tooltip) . "</div>";

        echo neptune_section_spacer();

        $title = _("Core Config Manager access").'<div tabindex="9" class="material-symbols-outlined pop md-20" data-content="'._('Allow user to view and access the CCM.').'<br><br><b>'._('None').'</b> - '._('Cannot use or view the CCM.').'<br><br><b>'._('Login').'</b> - '._('Can view CCM links and must log in with a CCM user account.').'<br><br><b>'._('Limited').'</b> - '._('Integrated CCM access. User can only access the objects they can view in the interface normally. Allows for setting specific permissions for the user.').'<br><br><b>'._('Full').'</b> - '._('Integrated CCM access. Can access all objects with no admin features.').'">help</div>';

        $ccm_access_options = array(
                "0" => _("None"),
                "1" => _("Login"),
                "2" => _("Limited"),
                "3" => _("Full"),
            );

?>
            <div class='user-setting'>
<?php    
        echo neptune_select($title, 'ccm_access', 'ccm_access', $ccm_access_options, $ccm_access);
?>
            </div>
            <div class="user-setting">
                <div class="ccm-limited-access-settings <?= ($ccm_access != 2 || is_admin($user_id) ? 'hide' : '') ?>">
                    <div class="well" style="margin: 0;">
<?php
        echo neptune_heading(_("Limited Access CCM Permissions").'<div class="text-medium flex gap-1">'._('Toggle').'<a class="toggle-all"> '._('All').'</a> / <a class="toggle-none">'._('None').'</a></div>', 'neptune-row-nowrap');
?>
                        <p>
                            <?= _('Users can only VIEW the below object types.'); ?><br>
                            <?= _('Select the object types to give them access to ADD, REMOVE, and EDIT.'); ?>
                        </p>
<?php
        echo neptune_subheading(_("Group Permissions"));
        echo neptune_centered_checkbox(_('Host Groups'), '', 'ccm_access_list[]', (in_array('hostgroup', $ccm_access_list) ? 'hostgroup' : ''), "hostgroup");
        echo neptune_centered_checkbox(_('Service Groups'), '', 'ccm_access_list[]', (in_array('servicegroup', $ccm_access_list) ? 'servicegroup' : ''), "servicegroup");

        echo neptune_section_spacer();
        echo neptune_subheading(_("Alerting Permissions"));
        echo neptune_centered_checkbox(_('Contacts'), '', 'ccm_access_list[]', (in_array('contact', $ccm_access_list) ? 'contact' : ''), "contact");
        echo neptune_centered_checkbox(_('Contact Groups'), '', 'ccm_access_list[]', (in_array('contactgroup', $ccm_access_list) ? 'contactgroup' : ''), "contactgroup");
        echo neptune_centered_checkbox(_('Time Periods'), '', 'ccm_access_list[]', (in_array('timeperiod', $ccm_access_list) ? 'timeperiod' : ''), "timeperiod");
        echo neptune_centered_checkbox(_('Host Escalations'), '', 'ccm_access_list[]', (in_array('hostescalation', $ccm_access_list) ? 'hostescalation' : ''), "hostescalation");
        echo neptune_centered_checkbox(_('Service Escalations'), '', 'ccm_access_list[]', (in_array('serviceescalation', $ccm_access_list) ? 'serviceescalation' : ''), "serviceescalation");

        echo neptune_section_spacer();
        echo neptune_subheading(_("Template Permissions"));
        echo neptune_centered_checkbox(_('Host Templates'), '', 'ccm_access_list[]', (in_array('hosttemplate', $ccm_access_list) ? 'hosttemplate' : ''), "hosttemplate");
        echo neptune_centered_checkbox(_('Service Templates'), '', 'ccm_access_list[]', (in_array('servicetemplate', $ccm_access_list) ? 'servicetemplate' : ''), "servicetemplate");
        echo neptune_centered_checkbox(_('Contact Templates'), '', 'ccm_access_list[]', (in_array('contacttemplate', $ccm_access_list) ? 'contacttemplate' : ''), "contacttemplate");

        echo neptune_section_spacer();
        echo neptune_subheading(_("Command Permissions"));
        echo neptune_centered_checkbox(_('Commands'), '', 'ccm_access_list[]', (in_array('command', $ccm_access_list) ? 'command' : ''), "command");

        echo neptune_section_spacer();
        echo neptune_subheading(_("Advanced Permissions"));
        echo neptune_centered_checkbox(_('Host Dependencies'), '', 'ccm_access_list[]', (in_array('hostdependency', $ccm_access_list) ? 'hostdependency' : ''), "hostdependency");
        echo neptune_centered_checkbox(_('Service Dependencies'), '', 'ccm_access_list[]', (in_array('servicedependency', $ccm_access_list) ? 'servicedependency' : ''), "servicedependency");
?>
                        <p style="margin-top: 10px;"><?= _("Tools are only available if assigned below."); ?></p>
<?php
        echo neptune_section_spacer();
        echo neptune_subheading(_("Tool Permissions"));
        echo neptune_centered_checkbox(_('Static Config Editor'), '', 'ccm_access_list[]', (in_array('staticconfig', $ccm_access_list) ? 'staticconfig' : ''), "staticconfig");
        echo neptune_centered_checkbox(_('User Macros'), '', 'ccm_access_list[]', (in_array('usermacros', $ccm_access_list) ? 'usermacros' : ''), "usermacros");
        echo neptune_centered_checkbox(_('Import Config Files'), '', 'ccm_access_list[]', (in_array('import', $ccm_access_list) ? 'import' : ''), "import");
        echo neptune_centered_checkbox(_('Config File Management'), '', 'ccm_access_list[]', (in_array('configmanagement', $ccm_access_list) ? 'configmanagement' : ''), "configmanagement");

        echo neptune_section_spacer();
?>
                        </div> <!-- well -->
                    </div> <!-- ccm-limited-access-settings -->
                </div> <!-- user-setting -->
            </div> <!-- class="col-lg-6 col-xl-4" -->
<?php
    }

    if (!is_neptune()) {
?>
            <div class="col-lg-6 col-xl-4">
<?php
        if (!$add) {
?>
                <h5 class="ul"><?= _('API Settings'); ?></h5>
                <table class="editDataSourceTable table table-condensed table-no-border" cellpadding="2">
                    <tr>
                        <td>
                            <label for="apikey"><?= _('API Key'); ?>:</label>
                        </td>
                        <td>
                            <input type="text" size="50" onClick="this.select();" value="<?= $api_key; ?>" class="textfield form-control" readonly name="apikey" id="apikey">
                        </td>
                    </tr>
                    <tr>
                        <td></td>
                        <td>
                            <button type="button" class="btn btn-xs btn-info" id="resetapikey" name="resetapikey" onclick="generate_new_api_key(<?= $user_id; ?>);"><?= _("Generate new API key"); ?></button>
                        </td>
                    </tr>

<?php
            // Let components add html to the edit/add user page (inside the api settings table)
            do_callbacks(CALLBACK_USER_EDIT_HTML_API_SETTINGS, $user_html_cbargs);
?>

                </table>
<?php
        }

        if (!$add && get_option('insecure_login', 0)) {
?>
                <h5 class="ul"><?= _('Insecure Login Settings'); ?> <div class="material-symbols-outlined pop md-20" tabindex="12" data-placement="top" data-content="<?= _('Allow the user to pass the URL parameters username and ticket to automatically log into the interface. This is insecure, and should be done through authentication tokens. This should only be used for backwards compatibility.'); ?>">help</div></h5>
                <table class="editDataSourceTable table table-condensed table-no-border" cellpadding="2">
                    <tr>
                        <td></td>
                        <td class="checkbox">
                            <label>
                                <input type="checkbox" id="insecure_login_enabled" name="insecure_login_enabled" <?= is_checked($insecure_login_enabled, 1); ?> value="1">
                                <?= _("Enable insecure login for this user"); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <label for="insecure_login_ticket"><?= _('Ticket'); ?>:&nbsp;&nbsp;&nbsp;</label>
                        </td>
                        <td>
                            <input type="text" size="50" onClick="this.select();" value="<?= $insecure_login_ticket; ?>" class="textfield form-control" readonly name="insecure_login_ticket" id="insecure_login_ticket">
                        </td>
                    </tr>
                    <tr>
                        <td></td>
                        <td>
                            <button type="button" class="btn btn-xs btn-info" id="resetticket" name="resetticket" onclick="generate_new_ticket(<?= $user_id ?>);"><?= _("Generate new insecure ticket") ?></button>
                        </td>
                    </tr>
                </table>
<?php
        }
?>
            </div>
        </div>
    </div>
<?php
        // Let components add html to the edit/add user page (outside of the main content div)
        do_callbacks(CALLBACK_USER_EDIT_HTML_GENERIC, $user_html_cbargs);

    } else {
?>        
            <div class="col-lg-6 col-xl-4">
<?php

        if (!$add) {
            echo neptune_heading(_("API Settings"));

            $button = '<button type="button" class="btn btn-xs btn-info" id="resetapikey" name="resetapikey" onclick="generate_new_api_key('.$user_id.')">'._("Generate new API key").'</button>';

            echo neptune_text_input_group_btn(_("API Key"), 'apikey', 'apikey', $api_key, '', '', 'input-group-long', $button, 'readonly onClick="this.select();"');

            // Let components add html to the edit/add user page (inside the api settings table)
            do_callbacks(CALLBACK_USER_EDIT_HTML_API_SETTINGS, $user_html_cbargs);
        }

        if (!$add && get_option('insecure_login', 0)) {

            $title = _('Insecure Login Settings').'<div class="material-symbols-outlined pop md-20" tabindex="12" data-placement="top" data-content="'._('Allow the user to pass the URL parameters username and ticket to automatically log into the interface. This is insecure, and should be done through authentication tokens. This should only be used for backwards compatibility.').'">help</div></h5>';

            echo neptune_section_spacer();
            echo neptune_heading($title);

            echo neptune_centered_checkbox(_("Enable insecure login for this user"), 'insecure_login_enabled', 'insecure_login_enabled', $insecure_login_enabled, 1);

            $button = '<button type="button" class="btn btn-xs btn-info" id="resetticket" name="resetticket" onclick="generate_new_ticket('.$user_id.')">'._("Generate new insecure ticket").'</button>';

            echo neptune_text_input_group_btn(_("Ticket"), 'insecure_login_ticket', 'insecure_login_ticket', $insecure_login_ticket, '', '', 'input-group-long', $button, 'readonly onClick="this.select();"');
        }
?>
            </div>
        </div>
    </div>
<?php
        // Let components add html to the edit/add user page (outside of the main content div)
        do_callbacks(CALLBACK_USER_EDIT_HTML_GENERIC, $user_html_cbargs);
    }
?>
        <div id="formButtons" style="margin-top: 10px;">
            <input type="submit" class="btn btn-sm btn-primary" name="updateButton" value="<?= $button_title; ?>" id="updateButton">
            <a class="btn btn-sm btn-default" href="users.php"> <?= _("Cancel"); ?></a>
        </div>

    </form>

    <script type="text/javascript" language="JavaScript">
        document.forms['updateForm'].elements['usernameBox'].focus();

        // Disables authorization checkboxes when Admin is selected as Admins can do everything -SW
        $(document).ready(function() {

            show_or_hide_settings();
            var arrVal = [ "255" ];

            $('select[name="level"]').change(function () {
                show_or_hide_settings();
            });

            $('#auth_type').change(function() {
                var type = $(this).val();

                if (type == 'ldap') {
                    $('.auth-ad').hide();
                    $('.auth-ldap').show();
                    $('.lo').hide();
                } else if (type == 'ad') {
                    $('.auth-ldap').hide();
                    $('.auth-ad').show();
                    $('.lo').hide();
                } else {
                    $('.auth-ad').hide();
                    $('.auth-ldap').hide();
                    $('.lo').show();
                }

                verify_allow_local();
            });

            $('#allow_local').click(function() {
                verify_allow_local();
            })

            $('#auth_type').trigger('change');
            verify_allow_local();
        });

        function verify_allow_local() {
            if ($('#allow_local').is(':checked') || $('#allow_local').is(':disabled') || $('#auth_type').val() == 'local') {
                $('.lo').show();
                $('.pw').show();
            } else {
                $('.lo').hide();
                $('.pw').hide();
            }
        }

        function show_or_hide_settings() {
            var level = $('select[name="level"] option:selected').val();
            if (level == 255) {
                $('.user-setting').hide();
            } else {
                $('.user-setting').show();
                $('#ccm_access').trigger('change');
            }
        }
    </script>

    <?php

    do_page_end(true);
    exit();
}


function do_update_user()
{
    global $request;

    // Check session
    check_nagios_session_protector();

    // Defaults
    $errmsg = array();
    $errors = 0;
    $changepass = false;
    $add = true;

    // Get values
    $username = grab_request_var("username", "");
    $email = grab_request_var("email", "");
    $name = grab_request_var("name", "");
    $level = grab_request_var("level", "user");
    $language = grab_request_var("language", "");
    $date_format = grab_request_var("defaultDateFormat", DF_ISO8601);
    $number_format = grab_request_var("defaultNumberFormat", NF_2);
    $week_format = grab_request_var("defaultWeekFormat", WF_US);
    $password1 = trim(grab_request_var("password1", ""));
    $phone = grab_request_var("phone", "");

    $illegal_chars = "/[`~!$%^&*|'<>?,=\"\(\)]/";

    // Validate that the username is a valid core contact name
    if (preg_match($illegal_chars, $username)) {
        $errmsg[$errors++] = _("The supplied username contains illegal characters.");
    }
    
    $add_contact = checkbox_binary(grab_request_var("add_contact", ""));
    if ($add_contact == 1) {
        $add_contact = true;
    } else {
        $add_contact = false;
    }

    $enabled = checkbox_binary(grab_request_var("enabled", ""));
    $enable_notifications = checkbox_binary(grab_request_var('enable_notifications', 0));
    $api_enabled = checkbox_binary(grab_request_var("api_enabled", 0));
    $insecure_login_enabled = grab_request_var("insecure_login_enabled", 0);

    $authorized_for_all_objects = checkbox_binary(grab_request_var("authorized_for_all_objects", ""));
    $authorized_to_configure_objects = checkbox_binary(grab_request_var("authorized_to_configure_objects", ""));
    $authorized_for_all_object_commands = checkbox_binary(grab_request_var("authorized_for_all_object_commands", ""));
    $authorized_for_monitoring_system = checkbox_binary(grab_request_var("authorized_for_monitoring_system", ""));
    $advanced_user = checkbox_binary(grab_request_var("advanced_user", ""));
    $readonly_user = checkbox_binary(grab_request_var("readonly_user", ""));

    $autodeploy_access = grab_request_var("autodeploy_access", 0);
    $ccm_access = grab_request_var("ccm_access", 0);
    $ccm_access_list = grab_request_var("ccm_access_list", array());

    // Grab authentication settings
    $auth_type = grab_request_var('auth_type', 'local');
    $ad_server = grab_request_var('ad_server', '');
    $ldap_server = grab_request_var('ldap_server', '');
    $ad_username = grab_request_var('ad_username', '');
    $dn = grab_request_var('dn', '');
    $allow_local = checkbox_binary(grab_request_var('allow_local', 0));

    if ($level == L_GLOBALADMIN) {
        $readonly_user = 0;
    }

    // Check for errors
    if (in_demo_mode() == true) {
        $errmsg[$errors++] = _("Changes are disabled while in demo mode.");
    }
    if (have_value($password1) == true) {
        if (strlen($password1) > 72) {
            $errmsg[$errors++] = _("Password provided must be less than 72 characters long.");
        }
        $changepass = true;
    }
    if (have_value($username) == false) {
        $errmsg[$errors++] = _("Username is blank.");
    }
    if ($username == "system") {
        $errmsg[$errors++] = _("Cannot use reserved username.");
    }
    if (have_value($email) == false) {
        $errmsg[$errors++] = _("Email address is blank.");
    } else if (!valid_email($email)) {
        $errmsg[$errors++] = _("Email address is invalid.");
    }
    if (have_value($name) == false) {
        $errmsg[$errors++] = _("Name is blank.");
    }
    if (have_value($level) == false) {
        $errmsg[$errors++] = _("Blank authorization level.");
    } else if (!is_valid_authlevel($level)) {
        $errmsg[$errors++] = _("Invalid authorization level.");
    }
    $user_id = grab_request_var("user_id");
    if (is_array($user_id)) {
        $user_id = current($user_id);
        if ($user_id != 0) {
            $add = false;
            // Make sure user exists
            if (!is_valid_user_id($user_id)) {
                $errmsg[$errors++] = _("User account was not found.") . " (ID=" . $user_id . ")";
            }
        }
    }
    if (have_value($password1) == false && $add == true) {
        $errmsg[$errors++] = _("Password cannot be blank.");
    }
    if ($level != L_GLOBALADMIN && $user_id == $_SESSION["user_id"]) {
        $errmsg[$errors++] = _("Authorization level demotion error.");
    }

    $forcechangepass = isset($request["forcepasswordchange"]) && $auth_type == 'local';

    // Verify if we are using a cloud license to not add/edit nagioscloud user
    if (is_v2_license_type('cloud')) {
        if ($username == "nagioscloud") {
            $errmsg[$errors++] = _("Cannot use reserved username.");
        }
        if ($user_id == 1) {
            $errmsg[$errors++] = _("Cannot edit this user.");
        }
    }

    // Handle errors
    if ($errors > 0) {
        show_edit_user(true, $errmsg);
    }

    // Add a new user
    if ($add == true) {

        if (!($user_id = add_user_account($username, $password1, $name, $email, $level, $forcechangepass, $add_contact, $api_enabled, $errmsg))) {
            show_edit_user(true, $errmsg);
            exit();
        }

        change_user_attr($user_id, 'created_time', time());
        change_user_attr($user_id, 'created_by', $_SESSION['user_id']);

        set_user_meta($user_id, 'name', $name);
        set_user_meta($user_id, 'language', $language);
        set_user_meta($user_id, "date_format", $date_format);
        set_user_meta($user_id, "number_format", $number_format);
        set_user_meta($user_id, "week_format", $week_format);
        set_user_meta($user_id, "authorized_for_all_objects", $authorized_for_all_objects);
        set_user_meta($user_id, "authorized_to_configure_objects", $authorized_to_configure_objects);
        set_user_meta($user_id, "authorized_for_all_object_commands", $authorized_for_all_object_commands);
        set_user_meta($user_id, "authorized_for_monitoring_system", $authorized_for_monitoring_system);
        set_user_meta($user_id, "advanced_user", $advanced_user);
        set_user_meta($user_id, "readonly_user", $readonly_user);
        set_user_meta($user_id, "mobile_number", $phone);

        set_user_meta($user_id, "autodeploy_access", $autodeploy_access);
        set_user_meta($user_id, "ccm_access", $ccm_access);
        set_user_meta($user_id, "ccm_access_list", base64_encode(serialize($ccm_access_list)));

        if ($add_contact) {
            set_user_meta($user_id, "enable_notifications", $enable_notifications);
        }

        // Set authentication settings
        set_user_meta($user_id, "auth_type", $auth_type);
        set_user_meta($user_id, "allow_local", $allow_local);
        if ($auth_type == 'ad') {
            set_user_meta($user_id, "auth_server_id", $ad_server);
            set_user_meta($user_id, "ldap_ad_username", $ad_username);
        } else if ($auth_type == 'ldap') {
            set_user_meta($user_id, "auth_server_id", $ldap_server);
            set_user_meta($user_id, "ldap_ad_dn", $dn);
        } else {
            submit_command(COMMAND_NAGIOSXI_SET_HTACCESS, serialize(array('username' => $username, 'password' => $password1)), 0, 0, null, true);
        }

        // Force no XI tour for now until help system reqrite TODO: replace
        $tour_settings = array("new_user" => 1);
        set_user_meta($user_id, "tours", serialize($tour_settings));

        // Update nagios cgi config file
        update_nagioscore_cgi_config();

        // Send email
        if (isset($request["sendemail"]) && ($auth_type == 'local' || !empty($allow_local))) {

            // Set email defaults
            $default_email_subject = _("%product% Account Created");
            $default_email_body = _("An account has been created for you to access %product%. You can login using the following information:\n\nUsername: %username%\nPassword: %password%\nURL: %url%\n\n");

            $password = $password1;
            $url = get_option("url");

            // Array of %options% to replace
            $args = array('product' => get_product_name(),
                          'username' => $username,
                          'password' => $password,
                          'url' => $url);

            // Get email template
            $email_subject = get_option('user_new_account_email_subject', $default_email_subject);
            $email_body = get_option('user_new_account_email_body', $default_email_body);

            // Process the macros
            $email_subject = replace_array_macros($email_subject, $args);
            $email_body = replace_array_macros($email_body, $args);

            // Use this for debug output in PHPmailer log
            $debugmsg = "";

            // Set where email is coming from for PHPmailer log
            $send_mail_referer = "admin/users.php > Account Creation";

            $opts = array(
                "to" => $email,
                "subject" => $email_subject,
                "message" => $email_body,
            );
            send_email($opts, $debugmsg, $send_mail_referer);
        }

        if ($level == L_GLOBALADMIN) {
            send_to_audit_log("User account '" . encode_form_val_minimal($username) . "' was created with GLOBAL ADMIN privileges", AUDITLOGTYPE_SECURITY);
        }
        send_to_audit_log("User account '" . encode_form_val_minimal($username) . "' was created", AUDITLOGTYPE_ADD);

        change_user_attr($user_id, "enabled", $enabled);

    } else {

        // Edit user...

        $oldlevel = get_user_meta($user_id, 'userlevel');
        $oldname = get_user_attr($user_id, 'name');
        $oldemail = get_user_attr($user_id, 'email');
        $oldusername = get_user_attr($user_id, 'username');
        $oldphone = get_user_meta($user_id, 'mobile_number');

        // add/update corresponding contact to/in Nagios Core
        if ($add_contact === true) {
            $contactargs = array(
                "contact_name" => $username,
                "alias" => $name,
                "email" => $email,
            );
            add_nagioscore_contact($contactargs);
        }

        // Update a few things when the username changes
        if ($username != $oldusername) {

            // Remove scheduled reports (we re-add at the end after username has changed)
            $reports = scheduledreporting_component_get_reports($user_id);
            if (!empty($reports)) {
                foreach ($reports as $id => $t) {
                    scheduledreporting_component_delete_cron($id, $user_id);
                }
            }

            // Actually change the username
            change_user_attr($user_id, 'username', $username);

            // Update Core contact
            delete_nagioscore_host_and_service_configs();
            rename_nagioscore_contact($oldusername, $username);

            // Update audit log username entries
            update_audit_log_entires(encode_form_val_minimal($oldusername), encode_form_val_minimal($username));

            // Re-add scheduled reports with new username
            if (!empty($reports)) {
                foreach ($reports as $id => $r) {
                    scheduledreporting_component_update_cron($id, $user_id);
                }
            }
        }

        // Update the nagioscore alias
        if ($name != $oldname) {
            change_user_attr($user_id, 'name', $name);
            rename_nagioscore_alias($username, $name);
        }

        // Update the nagioscore contact email
        if ($email != $oldemail) {
            change_user_attr($user_id, 'email', $email);
            update_nagioscore_contact($username, array('email' => $email));
        }

        // Update phone number if it's different
        if ($phone != $oldphone) {
            set_user_meta($user_id, 'mobile_number', $phone);
            set_user_meta($user_id, "phone_key", "");
            set_user_meta($user_id, "phone_key_expires", "");
            set_user_meta($user_id, "phone_verified", 0);
            set_user_meta($user_id, 'notify_by_mobiletext', 0, false);
        }

        if ($changepass == true) {
            if (is_valid_user_password($user_id, $password1)) {
                if (password_meets_complexity_requirements($password1)) {

                    // Save the old password hash for later
                    $old_pw_hash = get_user_attr($user_id, 'password');
                    user_add_old_password($user_id, $old_pw_hash);

                    // Change user password
                    change_user_attr($user_id, 'password', hash_password($password1));
                    change_user_attr($user_id, 'last_password_change', time());
                    submit_command(COMMAND_NAGIOSXI_SET_HTACCESS, serialize(array('username' => $username, 'password' => $password1)), 0, 0, null, true);

                    // User change password callback
                    do_user_password_change_callback($user_id, $password1);
                } else {
                    show_edit_user(true, _("Specified password does not meet the complexity requirements.") . get_password_requirements_message());
                    exit();
                }
            } else {
                show_edit_user(true, _("Password provided was previously used. You must use a new password."));
                exit();
            }
        }
        if ($forcechangepass) {
            set_user_meta($user_id, 'forcepasswordchange', "1");
        } else {
            delete_user_meta($user_id, 'forcepasswordchange');
        }

        change_user_attr($user_id, 'last_edited', time());
        change_user_attr($user_id, 'last_edited_by', $_SESSION['user_id']);

        set_user_meta($user_id, 'language', $language);
        set_user_meta($user_id, "date_format", $date_format);
        set_user_meta($user_id, "number_format", $number_format);
        set_user_meta($user_id, "week_format", $week_format);
        set_user_meta($user_id, 'userlevel', $level);
        set_user_meta($user_id, "authorized_for_all_objects", $authorized_for_all_objects);
        set_user_meta($user_id, "authorized_to_configure_objects", $authorized_to_configure_objects);
        set_user_meta($user_id, "authorized_for_all_object_commands", $authorized_for_all_object_commands);
        set_user_meta($user_id, "authorized_for_monitoring_system", $authorized_for_monitoring_system);
        set_user_meta($user_id, "advanced_user", $advanced_user);
        set_user_meta($user_id, "readonly_user", $readonly_user);
        change_user_attr($user_id, 'api_enabled', $api_enabled);
        set_user_meta($user_id, "insecure_login_enabled", $insecure_login_enabled);

        set_user_meta($user_id, "autodeploy_access", $autodeploy_access);
        set_user_meta($user_id, "ccm_access", $ccm_access);
        set_user_meta($user_id, "ccm_access_list", base64_encode(serialize($ccm_access_list)));
        if ($ccm_access == 2) {
            ccm_update_user_permissions($user_id);
        }

        $arr = get_user_nagioscore_contact_info($username);
        if ($arr["is_nagioscore_contact"] || $add_contact) {
            set_user_meta($user_id, "enable_notifications", $enable_notifications);
        }

        // Set authentication settings
        set_user_meta($user_id, "auth_type", $auth_type);
        set_user_meta($user_id, "allow_local", $allow_local);
        if ($auth_type == 'ad') {
            set_user_meta($user_id, "auth_server_id", $ad_server);
            set_user_meta($user_id, "ldap_ad_username", $ad_username);
        } else if ($auth_type == 'ldap') {
            set_user_meta($user_id, "auth_server_id", $ldap_server);
            set_user_meta($user_id, "ldap_ad_dn", $dn);
        }

        // Set session vars if this is the current user
        if ($user_id == $_SESSION["user_id"]) {
            $_SESSION["language"] = $language;
            $_SESSION["date_format"] = $date_format;
            $_SESSION["number_format"] = $number_format;
            $_SESSION["week_format"] = $week_format;
        }

        // Update nagios cgi config file
        update_nagioscore_cgi_config();

        // Send email
        if (isset($request["sendemail"]) && $changepass == true) {

            $password = $password1;
            $url = get_option("url");

            $message = sprintf(_("Your %s account password has been changed by an administrator. You can login using the following information:

Username: %s
Password: %s
URL: %s

"), get_product_name(), $username, $password, $url);

            // Use this for debug output in PHPmailer log
            $debugmsg = "";

            // Set where email is coming from for PHPmailer log
            $send_mail_referer = "admin/users.php > Administrator Password Reset";

            $opts = array(
                "to" => $email,
                "subject" => sprintf(_("%s Password Changed"), get_product_name()),
                "message" => $message,
            );
            send_email($opts, $debugmsg, $send_mail_referer);
        }

        // Log it (for privilege changes)
        if ($level == L_GLOBALADMIN && $oldlevel != L_GLOBALADMIN) {
            send_to_audit_log("User account '" . encode_form_val_minimal($username) . "' was granted GLOBAL ADMIN privileges", AUDITLOGTYPE_SECURITY);
        } else if ($level != L_GLOBALADMIN && $oldlevel == L_GLOBALADMIN) {
            send_to_audit_log("User account '" . encode_form_val_minimal($username) . "' had GLOBAL ADMIN privileges revoked", AUDITLOGTYPE_SECURITY);
        }
        send_to_audit_log("User account '" . encode_form_val_minimal($username) . "' has been edited", AUDITLOGTYPE_MODIFY);

        if (($user_id != $_SESSION["user_id"]) || ($enabled == 1)) {
            change_user_attr($user_id, "enabled", $enabled);
        }
    }

    $cbargs = array(
        'user_id'           => $user_id,
        'add'               => $add,
        'session_user_id'   => $_SESSION['user_id'],
    );
    do_callbacks(CALLBACK_USER_EDIT_PROCESS, $cbargs);

    if ($add == true) {
        header("Location: ?useradded");
    }
    else {

        flash_message(_('User') . " <b>".encode_form_val_minimal($username)."</b> " . _('updated.'), FLASH_MSG_SUCCESS);
        header("Location: users.php");
    }
}


function do_toggle_active_user()
{
    global $request;
    global $db_tables;

    check_nagios_session_protector();

    $toggle = grab_request_var("toggle_active") == "1" ? "1" : "0";

    $errmsg = array();
    $errors = 0;
    $user_id = grab_request_var("user_id", "");
    if (empty($user_id) || !is_numeric($user_id)) {
        $errmsg[$errors++] = _("Invalid user id.");
    }

    // Check for errors
    if (in_demo_mode() == true)
        $errors++;
        $errmsg = _("Changes are disabled while in demo mode.");
    if (!isset($request["user_id"])) {
        $errors++;
        $errmsg = _("No account selected.");
    } else {

        // Make sure user exists
        if (!is_valid_user_id($user_id)) {
            $errors++;
            $errmsg = _("User account was not found.") . " (ID=" . $user_id . ")";
        }

        // User can't disable their own account, but they can enable their own accounts
        if (($user_id == $_SESSION["user_id"]) && ($toggle == "0")) {
            $errors++;
            $errmsg = _("You cannot disable your own account.");
        }
    }

    // Disable the account
    if ($errors == 0) {
        if (change_user_attr($user_id, "enabled", $toggle)) {
            if ($toggle == "0") {
                flash_message(_("User account disabled."), FLASH_MSG_SUCCESS);
                show_users();
            } else {
                flash_message(_("User account enabled."), FLASH_MSG_SUCCESS);
                show_users();
            }
        } else {
            if ($toggle == "0") {
                $errors++;
                $errmsg = _("Unable to disable account.");
            } else {
                $errors++;
                $errmsg = _("Unable to enable account.");            
            }
        }
    }

    if ($errors > 0) {
        flash_message($errmsg, FLASH_MSG_ERROR);
        show_users();
    }
}


function do_delete_user()
{
    global $request;

    check_nagios_session_protector();

    $errmsg = array();
    $errors = 0;

    // Check for errors
    if (in_demo_mode() == true) {
        $errors++;
        $errmsg = _("Changes are disabled while in demo mode.");
    }
    if (!isset($request["user_id"])) {
        $errors++;
        $errmsg = _("No account selected.");
    } else {
        $user_id_arr = grab_request_var("user_id");
        foreach ($user_id_arr as $user_id) {

            // Make sure user exists
            if (!is_valid_user_id($user_id)) {
                $errors++;
                $errmsg = _("User account was not found.") . " (ID=" . $user_id . ")";
            }

            // User can't delete their own account
            if ($user_id == $_SESSION["user_id"]) {
                $errors++;
                $errmsg = _("You cannot delete your own account.");
            }

            // Do not delete nagioscloud or nagiosadmin
            if (is_v2_license_type('cloud')) {
                $username = get_user_attr($user_id, 'username');
                if ($username == "nagioscloud" || $username == "nagiosadmin") {
                    $errors++;
                    $errmsg = _("You cannot delete the nagiosadmin user.");
                }
            }
        }
    }

    if ($errors > 0) {
        flash_message($errmsg, FLASH_MSG_ERROR);
        show_users();
        return;
    }

    // Delete the accounts
    $user_id_arr = grab_request_var("user_id");
    foreach ($user_id_arr as $user_id) {
        update_nagioscore_cgi_config();
        $args = array(
            "username" => get_user_attr($user_id, 'username'),
        );
        submit_command(COMMAND_NAGIOSXI_DEL_HTACCESS, serialize($args), 0, 0, null, true);
        delete_user_id($user_id);

        // callback for user deletion
        $args['user_id'] = $user_id;
        do_callbacks(CALLBACK_USER_DELETED, $args);
    }

    $users = count($request["user_id"]);
    if ($users > 1) {
        $msg = $users . " " . _('users deleted.');
        flash_message($msg, FLASH_MSG_SUCCESS);
        show_users();
    }

    flash_message(_('User ').encode_form_val_minimal($args['username'])._(' deleted.'), FLASH_MSG_SUCCESS);
    show_users();
}


function do_unlock_user() {

    global $request;

    check_nagios_session_protector();

    $errmsg = array();
    $msg = "";
    $errors = 0;

    // Check for errors
    if (in_demo_mode() == true)
        $errmsg[$errors++] = _("Changes are disabled while in demo mode.");

    if (!isset($request["user_id"]))
        $errmsg[$errors++] = _("No account selected.");

    $user_id_arr = grab_request_var("user_id");
    foreach ($user_id_arr as $user_id) {

        // Make sure user exists
        if (!is_valid_user_id($user_id)) {
            $errmsg[$errors++] = _("User account was not found.") . " (ID=" . $user_id . ")";
        }

        if (!change_user_attr($user_id, "login_attempts", 0) || !change_user_attr($user_id, "last_attempt", 0)) {
            $errmsg[$errors++] = _("Unable to unlock account.") . " (ID=" . $user_id . ")";
        }
    }

    if ($errors > 0) {
        show_users(true, $errmsg);
    } else {
        show_users(false, count($user_id_arr) . " " . _("User Accounts Unlocked."));
    }
}


/**
 * @param bool   $error
 * @param string $msg
 */
function show_clone_user($error = false, $msg = "")
{
    global $request;
    $add_contact = 1;

    // get options
    $user_id = grab_request_var("user_id", 0);
    if (is_array($user_id)) {
        $user_id = current($user_id);
    }

    if ($error == false) {
        if (isset($request["updated"]))
            $msg = _("User Updated.");
        else if (isset($request["added"]))
            $msg = _("User Added.");
    }

    // make sure user exists first
    if (!is_valid_user_id($user_id)) {
        show_users(true, _("User account was not found.") . " (ID=" . encode_form_val($user_id) . ")");
    }

    $username = grab_request_var("username", "");
    $email = grab_request_var("email", "");
    $name = grab_request_var("name", "");

    $password1 = "";
    $forcepasswordchange = get_user_meta($user_id, "forcepasswordchange");

    $passwordbox1title = _("New Password");
    $passwordbox2title = _("Repeat New Password");

    $sendemail = "0";
    $sendemailboxtitle = _("Email User New Password");

    $page_title = _("Clone User");
    $button_title = _("Clone User");

    $page_header = _("Clone User") . ": " . encode_form_val(get_user_attr($user_id, "username"));

    if (is_neptune()) {
        $page_header = _("Clone User") . ' <span class="text-large pl-1">(' . encode_form_val(get_user_attr($user_id, "username")) . ')</span>';
    }

    do_page_start(array("page_title" => $page_title), true);

    echo neptune_page_title($page_header, _('Create a new user account based on the account selected. The cloned account will inherit all preferences, views, and dashboards from the original account.'));
?>
    <?php display_message($error, false, $msg); ?>

    <script type="text/javascript">
        $(document).ready(function () {
            $("#passwordBox1").change(function () {
                $("#updateForm").checkCheckboxes("#forcePasswordChangeBox", true);
                $("#updateForm").checkCheckboxes("#sendEmailBox", true);
            });
        });
    </script>

    <form id="updateForm" method="post" action="?">
        <input type="hidden" name="doclone" value="1">
        <?= get_nagios_session_protector(); ?>
        <input type="hidden" name="user_id[]" value="<?= encode_form_val($user_id); ?>">
<?php
    if (!is_neptune()) {
?>
        <h5 class="ul"><?= _("General Settings"); ?></h5>
        <table class="editDataSourceTable table table-condensed table-no-border table-auto-width">
            <tr>
                <td>
                    <label for="usernameBox"><?= _("Username"); ?>:</label>
                </td>
                <td>
                    <input type="text" size="15" name="username" id="usernameBox" value="<?= encode_form_val_minimal($username); ?>" class="form-control">
                </td>
            </tr>

            <tr>
                <td>
                    <label for="passwordBox1"><?= $passwordbox1title; ?>:</label>
                </td>
                <td>
                    <input type="password" size="10" name="password1" id="passwordBox1" value="<?= encode_form_val($password1); ?>" class="form-control" <?= sensitive_field_autocomplete(); ?>>
                    <button type="button" style="vertical-align: top;" class="btn btn-sm btn-default tt-bind btn-show-password" title="<?= _("Show password"); ?>"><i class="material-symbols-outlined md-20">Visibility</i></button>
                </td>
            </tr>

            <tr>
                <td></td>
                <td>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" class="checkbox" id="forcePasswordChangeBox" name="forcepasswordchange" <?= is_checked($forcepasswordchange, "1"); ?>>
                            <?= _('Force password change at next login'); ?>
                        </label>
                    </div>
                </td>
            </tr>

            <tr>
                <td></td>
                <td>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" class="checkbox" id="sendEmailBox" name="sendemail" <?= is_checked($sendemail, "1"); ?>>
                            <?= _('Email user new password'); ?>
                        </label>
                    </div>
                </td>
            </tr>

            <tr>
                <td>
                    <label for="nameBox"><?= _("Name"); ?>:</label>
                </td>
                <td>
                    <input type="text" size="30" name="name" id="nameBox" value="<?= encode_form_val($name); ?>" class="form-control">
                </td>
            </tr>

            <tr>
                <td>
                    <label for="emailAddressBox"><?= _("Email Address"); ?>:</label>
                </td>
                <td>
                    <input type="text" size="30" name="email" id="emailAddressBox" value="<?= encode_form_val($email); ?>" class="form-control">
                </td>
            </tr>

            <tr>
                <td></td>
                <td>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" class="checkbox" id="addContactBox" name="add_contact" <?= is_checked($add_contact, 1); ?>>
                            <?= _('Create as monitoring contact'); ?>
                        </label>
                    </div>
                </td>
            </tr>

        </table>
<?php        
    } else {
        echo neptune_heading(_('General Settings'));

        echo neptune_text(_("Username"), 'usernameBox', 'username', encode_form_val_minimal($username), '', '', 'form-item-required');

        echo neptune_password($passwordbox1title, 'passwordBox1', 'password1', encode_form_val($password1), '', '', '', 'form-item-required');

        echo neptune_centered_checkbox(_('Force password change at next login'), 'forcePasswordChangeBox', 'forcepasswordchange', $forcepasswordchange, "1");

        echo neptune_centered_checkbox(_('Email user new password'), 'sendEmailBox', 'sendemail', $sendemail, "1");

        echo neptune_section_spacer();

        echo neptune_text(_("Alias (Name)"), 'nameBox', 'name', encode_form_val($name), '', '', 'form-item-required');

        echo neptune_text(_("Email Address"), 'emailAddressBox', 'email', encode_form_val($email), '', '', 'form-item-required');

        echo neptune_centered_checkbox(_('Create as monitoring contact'), 'addContactBox', 'add_contact', $add_contact, 1);

        echo neptune_section_spacer();
    }
?>
        <div id="formButtons" class="btn-row">
            <button type="submit" class="btn btn-sm btn-primary" name="updateButton" id="updateButton"><?= $button_title ?></button>
            <input type="submit" class="btn btn-sm btn-default" name="cancelButton" value="<?= _('Cancel') ?>" id="cancelButton">
        </div>

    </form>

    <script type="text/javascript" language="JavaScript">
        document.forms['updateForm'].elements['usernameBox'].focus();
    </script>
<?php
    do_page_end(true);
    exit();
}


function do_clone_user()
{
    global $request;

    // user pressed the cancel button
    if (isset($request["cancelButton"])) {
        show_users(false, "");
        exit();
    }

    // check session
    check_nagios_session_protector();

    $errmsg = array();
    $errors = 0;

    // get values
    $username = grab_request_var("username", "");
    $email = grab_request_var("email", "");
    $name = grab_request_var("name", "");
    $password1 = trim(grab_request_var("password1", ""));

    $add_contact = checkbox_binary(grab_request_var("add_contact", ""));
    if ($add_contact == 1)
        $add_contact = true;
    else
        $add_contact = false;

    // check for errors
    if (in_demo_mode() == true)
        $errmsg[$errors++] = _("Changes are disabled while in demo mode.");
    if (have_value($password1) == false)
        $errmsg[$errors++] = _("Password cannot be blank.");
    if (have_value($username) == false)
        $errmsg[$errors++] = _("Username is blank.");
    if (have_value($email) == false)
        $errmsg[$errors++] = _("Email address is blank.");
    else if (!valid_email($email))
        $errmsg[$errors++] = _("Email address is invalid.");
    if (have_value($name) == false)
        $errmsg[$errors++] = _("Name is blank.");

    $user_id = grab_request_var("user_id", 0);
    if (is_array($user_id)) {
        $user_id = current($user_id);
        if ($user_id != 0) {
            // make sure user exists
            if (!is_valid_user_id($user_id)) {
                $errmsg[$errors++] = _("User account was not found.") . " (ID=" . $user_id . ")";
            }
        }
    }

    $forcechangepass = isset($request["forcepasswordchange"]);

    // Verify we are not trying to clone the nagioscloud account
    if (is_v2_license_type('cloud') && $user_id == 1) {
        $errmsg[$errors++] = _('Could not clone this user.');
    }

    // handle errors
    if ($errors > 0)
        show_clone_user(true, $errmsg);

    // log it
    $original_user = get_user_attr($user_id, "username");
    send_to_audit_log("User cloned account '" . $original_user . "'", AUDITLOGTYPE_SECURITY);

    // add the new user
    $level = get_user_meta($user_id, "userlevel");
    $api_enabled = get_user_attr($user_id, "api_enabled");
    if (!($new_user_id = add_user_account($username, $password1, $name, $email, $level, $forcechangepass, $add_contact, $api_enabled, $errmsg))) {
        show_clone_user(true, $errmsg);
    }

    // copy over all meta data from original user
    $meta = get_all_user_meta($user_id);
    if ($meta) {
        foreach ($meta as $m) {

            // skip a few types of meta data
            if ($m['keyname'] == "userlevel")
                continue;
            if ($m['keyname'] == "forcepasswordchange")
                continue;
            if ($m['keyname'] == "lastlogintime")
                continue;
            if ($m['keyname'] == "timesloggedin")
                continue;

            set_user_meta($new_user_id, $m['keyname'], $m['keyvalue']);
        }
    }

    // send email
    if (isset($request["sendemail"])) {

        $password = $password1;
        $url = get_option("url");

        $message = sprintf(_("An account has been created for you to access %s. You can login using the following information:

Username: %s
Password: %s
URL: %s

"), get_product_name(), encode_form_val_minimal($username), $password, $url);

        // Use this for debug output in PHPmailer log
        $debugmsg = "";

        // Set where email is coming from for PHPmailer log
        $send_mail_referer = "admin/users.php > Clone User - Account Creation";

        $opts = array(
            "to" => $email,
            "subject" => sprintf(_("%s Account Created"), get_product_name()),
            "message" => $message,
        );
        send_email($opts, $debugmsg, $send_mail_referer);
    }

    // success!
    header("Location: ?usercloned");
}


function do_masquerade()
{
    check_nagios_session_protector();

    $user_id = grab_request_var("user_id", -1);

    // Check for valid user and if cloud nagiosadmin user
    if (!is_valid_user_id($user_id) || (is_v2_license_type('cloud') && $user_id == 1)) {
        show_users(false, _("Invalid account."));
        exit();
    }

    if (get_user_attr($user_id, "enabled") < 1) {
        show_users(false, _("Account is disabled."));
        exit();
    }

    // do the magic masquerade stuff...
    masquerade_as_user_id($user_id);

    // redirect to home page
    header("Location: " . get_base_url());
}


function is_checked_admin($var1, $var2, $level)
{
    if ($level == 255) {
        return "checked";
    } else if ($var1 == $var2) {
        return "checked";
    }

    return "";
}

function admin_checkbox_binary($var, $level) {
    if (is_numeric($var)) {
        if ($var == 1) {
            return 1;
        }
    } else if (is_string($var) && $var == "on") {
        return 1;
    }
    if ($level == 255) {
        return 1;
    }
    return 0;
}