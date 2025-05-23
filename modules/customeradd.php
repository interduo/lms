<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2021 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

if (isset($_GET['ajax'])) {
    header('Content-type: text/plain');
    $search = urldecode(trim($_GET['what']));

    switch ($_GET['mode']) {
        case 'street':
            $mode = 'street';
            break;

        case 'zip':
            $mode = 'zip';
            break;

        case 'city':
            $mode = 'city';
            break;
    }

    if (!isset($mode)) {
        print 'false;';
        exit;
    }

    $candidates = $DB->GetAll('SELECT ' . $mode . ' as item, count(id) AS entries
		FROM customerview
		WHERE ' . $mode . ' != \'\' AND lower(' . $mode . ') ?LIKE? lower(' . $DB->Escape('%' . $search . '%') . ')
		GROUP BY item
		ORDER BY entries DESC, item ASC
		LIMIT 15');

    $result = array();
    if ($candidates) {
        foreach ($candidates as $idx => $row) {
            $name = $row['item'];
            $name_class = '';
            $description = $row['entries'] . ' ' . trans('entries');
            $description_class = '';
            $action = '';

            $result[$row['item']] = compact('name', 'name_class', 'description', 'description_class', 'action');
        }
    }
    header('Content-Type: application/json');
    echo json_encode(array_values($result));
    exit;
}

require_once(LIB_DIR . DIRECTORY_SEPARATOR . 'customerconsents.php');
require_once(LIB_DIR . DIRECTORY_SEPARATOR . 'customercontacttypes.php');

$customeradd = array();

$natural_person_required_properties = ConfigHelper::getConfig(
    'customers.natural_person_required_properties',
    ConfigHelper::getConfig(
        'phpui.natural_person_required_properties',
        '',
        true
    ),
    true
);
$natural_person_required_properties = array_flip(preg_split('/([\s]+|[\s]*,[\s]*)/', $natural_person_required_properties));
$legal_person_required_properties = ConfigHelper::getConfig(
    'customers.legal_person_required_properties',
    ConfigHelper::getConfig(
        'phpui.legal_person_required_properties',
        '',
        true
    ),
    true
);
$legal_person_required_properties = array_flip(preg_split('/([\s]+|[\s]*,[\s]*)/', $legal_person_required_properties));

$natural_person_required_property_validation_error = ConfigHelper::checkConfig(
    'customers.natural_person_required_property_validation_error',
    true
);
$legal_person_required_property_validation_error = ConfigHelper::checkConfig(
    'customers.legal_person_required_property_validation_error',
    true
);
$natural_person_required_property_validation_customer_statuses = Utils::determineAllowedCustomerStatus(
    ConfigHelper::getConfig('customers.natural_person_required_property_validation_customer_statuses')
);
$legal_person_required_property_validation_customer_statuses = Utils::determineAllowedCustomerStatus(
    ConfigHelper::getConfig('customers.legal_person_required_property_validation_customer_statuses')
);

$groups = trim(ConfigHelper::getConfig('customers.groups_required_on_add', ConfigHelper::getConfig('phpui.add_customer_group_required', 'false')));
if (preg_match('/^(0|n|no|off|false|nie|disabled)$/i', $groups)) {
    $groups_required = false;
} else {
    $groups_required = strlen($groups) > 0;
}

if ($groups_required) {
    $all_groups = $DB->GetAll('SELECT id, name FROM customergroups ORDER BY id');
    if (empty($all_groups)) {
        $groups_required = false;
    } else {
        $SMARTY->assign('groups', $all_groups);
    }
}

$default_consents = Utils::getDefaultCustomerConsents();

if (isset($_POST['customeradd'])) {
    $customeradd = $_POST['customeradd'];

    $required_properties = $customeradd['type'] == CTYPES_COMPANY ? $legal_person_required_properties : $natural_person_required_properties;
    $required_property_validation_error = $customeradd['type'] == CTYPES_COMPANY ? $legal_person_required_property_validation_error : $natural_person_required_property_validation_error;
    $required_property_validation_customer_statuses = $customeradd['type'] == CTYPES_COMPANY ? $legal_person_required_property_validation_customer_statuses : $natural_person_required_property_validation_customer_statuses;

    $contacttypes = array_keys($CUSTOMERCONTACTTYPES);
    foreach ($contacttypes as &$contacttype) {
        $contacttype .= 's';
    }

    if (count($customeradd)) {
        foreach ($customeradd as $key => $value) {
            if ($key != 'uid' && $key != 'wysiwyg' && !in_array($key, $contacttypes)) {
                $customeradd[$key] = trim_rec($value);
            }
        }
    }

    if ($customeradd['lastname'] == '') {
        $error['lastname'] = trans('Last/Company name cannot be empty!');
    }

    if ($customeradd['name'] == '' && !$customeradd['type']) {
        $error['name'] = trans('First name cannot be empty!');
    }

    if ($groups_required && empty($customeradd['group'])) {
        $error['group'] = trans('Group name required!');
    }

    // check addresses
    foreach ($customeradd['addresses'] as $k => $v) {
        if ($v['location_address_type'] == BILLING_ADDRESS && !$v['location_city_name']) {
            $error['customeradd[addresses][' . $k . '][location_city_name]'] = trans('City name required!');
            $customeradd['addresses'][ $k ]['show'] = true;
        }

        $countryCode = null;
        if (!empty($v['location_country_id'])) {
            $countryCode = $LMS->getCountryCodeById($v['location_country_id']);
            if ($v['location_address_type'] == BILLING_ADDRESS) {
                $billingCountryCode = $countryCode;
            }
        }

        if (!ConfigHelper::checkPrivilege('full_access') && ConfigHelper::checkConfig('phpui.teryt_required')
            && !empty($v['location_city_name']) && ($v['location_country_id'] == 2 || empty($v['location_country_id']))
            && (!isset($v['teryt']) || empty($v['location_city'])) && $LMS->isTerritState($v['location_state_name'])) {
            $error['customeradd[addresses][' . $k . '][teryt]'] = trans('TERYT address is required!');
            $customeradd['addresses'][ $k ]['show'] = true;
        }

        Localisation::setSystemLanguage($countryCode);
        if ($v['location_zip']) {
            $zip_validation_result = check_zip($v['location_zip']);
            if (isset($zip_validation_result) && !$zip_validation_result) {
                $error['customeradd[addresses][' . $k . '][location_zip]'] = trans('Incorrect ZIP code!');
                $customeradd['addresses'][$k]['show'] = true;
            }
        }
    }

    if (isset($billingCountryCode)) {
        Localisation::setSystemLanguage($billingCountryCode);
    }

    if (isset($customeradd['icexpires'])) {
        $ic_expires = $customeradd['icexpires'] && $customeradd['icexpires'] < time();
        if ($ic_expires) {
            $identity_card_expiration_check = ConfigHelper::getConfig(
                'customers.identity_card_expiration_check',
                ConfigHelper::getConfig(
                    'phpui.customer_identity_card_expiration_check',
                    'none'
                )
            );
            switch ($identity_card_expiration_check) {
                case 'warning':
                    if (!isset($warnings['customeradd-icexpires-'])) {
                        $warning['customeradd[icexpires]'] = trans('Customer identity card expired or expires soon!');
                    }
                    break;
                case 'error':
                    $error['icexpires'] = trans('Customer identity card expired or expires soon!');
                    break;
            }
        }
    }

    if (empty($customeradd['origin'])) {
        $origin_check = ConfigHelper::getConfig(
            'customers.origin_check',
            'none'
        );
        switch ($origin_check) {
            case 'warning':
                if (!isset($warnings['customeradd-origin-'])) {
                    $warning['customeradd[origin]'] = trans('Customer origin is empty!');
                }
                break;
            case 'error':
                $error['origin'] = trans('Customer origin is required!');
                break;
        }
    }

    if (isset($customeradd['ten'])) {
        if ($customeradd['ten'] != '') {
            if (!isset($customeradd['tenwarning'])) {
                $ten_validation_result = check_ten($customeradd['ten']);
                if (isset($ten_validation_result) && !$ten_validation_result) {
                    $warning['ten'] = trans('Incorrect Tax Exempt Number! If you are sure you want to accept it, then click "Submit" again.');
                    $customeradd['tenwarning'] = 1;
                }
            }
            $ten_existence_check = ConfigHelper::getConfig(
                'customers.ten_existence_check',
                ConfigHelper::getConfig('phpui.customer_ten_existence_check', 'none')
            );
            $ten_existence_scope = ConfigHelper::getConfig(
                'customers.ten_existence_scope',
                ConfigHelper::getConfig('phpui.customer_ten_existence_scope', 'global')
            );
            if (preg_match('/^(global|division)$/', $ten_existence_scope)) {
                $ten_existence_scope = 'global';
            }
            $ten_exists = $LMS->checkCustomerTenExistence(
                null,
                $customeradd['ten'],
                $ten_existence_scope == 'global' ? null : $customeradd['divisionid']
            );
            switch ($ten_existence_check) {
                case 'warning':
                    if (!isset($customeradd['tenexistencewarning']) && $ten_exists) {
                        $warning['ten'] = trans('Customer with specified Tax Exempt Number already exists! If you are sure you want to accept it, then click "Submit" again.');
                        $customeradd['tenexistencewarning'] = 1;
                    }
                    break;
                case 'error':
                    if ($ten_exists) {
                        $error['ten'] = trans('Customer with specified Tax Exempt Number already exists!');
                    }
                    break;
            }
        } elseif (in_array($customeradd['status'], $required_property_validation_customer_statuses)
            && isset($required_properties['ten'])) {
            if ($required_property_validation_error) {
                $error['ten'] = trans('Missed required TEN identifier!');
            } elseif (!isset($warnings['customeradd-ten-'])) {
                $warning['customeradd[ten]'] = trans('Missed recommended TEN identifier!');
            }
        }
    }

    if (isset($customeradd['ssn'])) {
        if ($customeradd['ssn'] != '') {
            if (!isset($customeradd['ssnwarning'])) {
                $ssn_validation_result = check_ssn($customeradd['ssn']);
                if (isset($ssn_validation_result) && !$ssn_validation_result) {
                    $warning['ssn'] = trans('Incorrect Social Security Number! If you are sure you want to accept it, then click "Submit" again.');
                    $customeradd['ssnwarning'] = 1;
                }
            }
            $ssn_existence_check = ConfigHelper::getConfig(
                'customers.ssn_existence_check',
                ConfigHelper::getConfig('phpui.customer_ssn_existence_check', 'none')
            );
            $ssn_existence_scope = ConfigHelper::getConfig(
                'customers.ssn_existence_scope',
                ConfigHelper::getConfig('phpui.customer_ssn_existence_scope', 'global')
            );
            if (preg_match('/^(global|division)$/', $ssn_existence_scope)) {
                $ssn_existence_scope = 'global';
            }
            $ssn_exists = $LMS->checkCustomerSsnExistence(
                null,
                $customeradd['ssn'],
                $ssn_existence_scope == 'global' ? null : $customeradd['divisionid']
            );
            switch ($ssn_existence_check) {
                case 'warning':
                    if (!isset($customeradd['ssnexistencewarning']) && $ssn_exists) {
                        $warning['ssn'] = trans('Customer with specified Social Security Number already exists! If you are sure you want to accept it, then click "Submit" again.');
                        $customeradd['ssnexistencewarning'] = 1;
                    }
                    break;
                case 'error':
                    if ($ssn_exists) {
                        $error['ssn'] = trans('Customer with specified Social Security Number already exists!');
                    }
                    break;
            }
        } elseif (in_array($customeradd['status'], $required_property_validation_customer_statuses)
            && isset($required_properties['ssn'])) {
            if ($required_property_validation_error) {
                $error['ssn'] = trans('Missed required SSN identifier!');
            } elseif (!isset($warnings['customeradd-ssn-'])) {
                $warning['customeradd[ssn]'] = trans('Missed recommended SSN identifier!');
            }
        }
    }

    if (isset($customeradd['icn'])) {
        if ($customeradd['icn'] != '' && $customeradd['ict'] == 0 && !isset($customeradd['icnwarning'])) {
            $icn_validation_result = check_icn($customeradd['icn']);
            if (isset($icn_validation_result) && !$icn_validation_result) {
                $warning['icn'] = trans('Incorrect Identity Card Number! If you are sure you want to accept, then click "Submit" again.');
                $icnwarning = 1;
            }
        } elseif ($customeradd['icn'] == ''
            && in_array($customeradd['status'], $required_property_validation_customer_statuses)
            && isset($required_properties['icn'])) {
            if ($required_property_validation_error) {
                $error['icn'] = trans('Missed required Identity Card Number!');
            } elseif (!isset($warnings['customeradd-icn-'])) {
                $warning['customeradd[icn]'] = trans('Missed recommended Identity Card Number!');
            }
        }
    }

    if ($customeradd['regon'] != '') {
        $regon_validation_result = check_regon($customeradd['regon']);
        if (isset($regon_validation_result) && !$regon_validation_result) {
            $error['regon'] = trans('Incorrect Business Registration Number!');
        }
    }

    Localisation::resetSystemLanguage();

    $pin_check_result = $LMS->checkCustomerPin(null, $customeradd['pin']);
    if (is_string($pin_check_result)) {
        $error['pin'] = $pin_check_result;
    }

    $contacts = array();

    $emaileinvoice = false;

    foreach ($CUSTOMERCONTACTTYPES as $contacttype => $properties) {
        $properties['validator']($customeradd, $contacts, $error);
    }

    $customer_invoice_notice_consent_check = ConfigHelper::getConfig(
        'customers.invoice_notice_consent_check',
        ConfigHelper::getConfig('phpui.customer_invoice_notice_consent_check', 'error')
    );
    if ($customer_invoice_notice_consent_check != 'none') {
        if (!empty($customeradd['emails'])) {
            foreach ($customeradd['emails'] as $idx => $val) {
                if ($val['type'] & (CONTACT_INVOICES | CONTACT_DISABLED)) {
                    $emaileinvoice = true;
                }
            }
        }
    }

    if (isset($customeradd['consents'][CCONSENT_INVOICENOTICE]) && !$emaileinvoice) {
        if ($customer_invoice_notice_consent_check == 'error') {
            $error['chkconsent' . CCONSENT_INVOICENOTICE] =
                trans('If the customer wants to receive an electronic invoice must be checked e-mail address to which to send e-invoices');
        } elseif ($customer_invoice_notice_consent_check == 'warning'
            && !isset($warnings['customeradd-consents--' . CCONSENT_INVOICENOTICE . '-'])) {
            $warning['customeradd[consents][' . CCONSENT_INVOICENOTICE . ']'] =
                trans('If the customer wants to receive an electronic invoice must be checked e-mail address to which to send e-invoices');
        }
    }

    if (isset($customeradd['cutoffstopindefinitely'])) {
        $cutoffstop = intval(2 ** 31 - 1);
    } elseif ($customeradd['cutoffstop'] == '') {
        $cutoffstop = 0;
    } elseif ($cutoffstop = date_to_timestamp($customeradd['cutoffstop'])) {
        $cutoffstop = strtotime('tomorrow', $cutoffstop) - 1;
    } else {
        $error['cutoffstop'] = trans('Incorrect date of cutoff suspending!');
    }

    if (!preg_match('/^[\-]?[0-9]+$/', $customeradd['paytime'])) {
        $error['paytime'] = trans('Invalid deadline format!');
    }

    $hook_data = $LMS->executeHook(
        'customeradd_validation_before_submit',
        array(
            'customeradd' => $customeradd,
            'error' => $error,
            'warning' => $warning,
        )
    );
    $customeradd = $hook_data['customeradd'];
    $error = $hook_data['error'];
    $warning = $hook_data['warning'];

    if (!$error && !$warning) {
        $customeradd['cutoffstop'] = $cutoffstop;

        if (!isset($customeradd['consents'])) {
            $customeradd['consents'] = array();
        }

        if (!isset($customeradd['divisionid'])) {
            $customeradd['divisionid'] = 0;
        }

        $id = $LMS->CustomerAdd($customeradd);

        $hook_data = $LMS->executeHook(
            'customeradd_after_submit',
            array(
                    'id' => $id,
                    'customeradd' => $customeradd,
                )
        );
        $customeradd = $hook_data['customeradd'];
        $id = $hook_data['id'];

        if ($id && !empty($contacts)) {
            foreach ($contacts as $contact) {
                if ($contact['type'] & CONTACT_BANKACCOUNT) {
                    $contact['contact'] = preg_replace('/[^a-zA-Z0-9]/', '', $contact['contact']);
                }
                $DB->Execute('INSERT INTO customercontacts (customerid, contact, name, type)
					VALUES(?, ?, ?, ?)', array($id, $contact['contact'], $contact['name'], $contact['type']));
                if ($SYSLOG) {
                    $contactid = $DB->GetLastInsertID('customercontacts');
                    $args = array(
                        SYSLOG::RES_CUSTCONTACT => $contactid,
                        SYSLOG::RES_CUST => $id,
                        'contact' => $contact['contact'],
                        'name' => $contact['name'],
                        'type' => $contact['type'],
                    );
                    $SYSLOG->AddMessage(SYSLOG::RES_CUSTCONTACT, SYSLOG::OPER_ADD, $args);
                }
            }
        }

        if (!isset($customeradd['reuse'])) {
            $SESSION->redirect('?m=customerinfo&id='.$id);
        }

        $reuse['status'] = $customeradd['status'];
        foreach (array_keys($CUSTOMERCONTACTTYPES) as $contacttype) {
            $reuse[$contacttype . 's'][] = array();
        }
        unset($customeradd);
        $customeradd = $reuse;
        $customeradd['reuse'] = '1';
    }
} else {
    $customeradd['emails'] = array(
        0 => array(
            'contact' => '',
            'name' => '',
            'type' => empty($contact_default_flags['email']) ? 0 : array_sum($contact_default_flags['email']),
        )
    );
    $customeradd['phones'] = array(
        0 => array(
            'contact' => '',
            'name' => '',
            'type' => empty($contact_default_flags['phone']) ? 0 : array_sum($contact_default_flags['phone']),
        )
    );

    $customeradd['status'] = intval(ConfigHelper::getConfig(
        'customers.default_status',
        ConfigHelper::getConfig('phpui.default_status')
    ));

    $customeradd['divisionid'] = intval(ConfigHelper::getConfig(
        'customers.default_divisionid',
        ConfigHelper::getConfig('phpui.default_divisionid')
    ));

    $customeradd['documentmemo'] = ConfigHelper::getConfig(
        'customers.default_document_memo',
        ConfigHelper::getConfig('phpui.default_customer_document_memo', '', true),
        true
    );

    $customeradd['consents'] = $default_consents;

    $customer_type = trim(ConfigHelper::getConfig(
        'customers.default_type',
        ConfigHelper::getConfig('phpui.default_customer_type', $CTYPE_ALIASES[CTYPES_PRIVATE])
    ));
    $ctype_aliases_flipped = array_flip($CTYPE_ALIASES);
    if (isset($ctype_aliases_flipped[$customer_type])) {
        $customeradd['type'] = $ctype_aliases_flipped[$customer_type];
    }

    $customer_flags = trim(ConfigHelper::getConfig(
        'customers.default_flags',
        ConfigHelper::getConfig('phpui.default_customer_flags', '', true),
        true
    ));
    $customer_flags_flipped = array();
    foreach ($CUSTOMERFLAGS as $customer_flag => $customer_flag_properties) {
        $customer_flags_flipped[$customer_flag_properties['alias']] = $customer_flag;
    }
    $customeradd['flags'] = array_map(
        function ($value) use ($customer_flags_flipped) {
            return $customer_flags_flipped[$value] ?? 0;
        },
        preg_split("/([\s]+|[\s]*,[\s]*)/", strtolower($customer_flags), -1, PREG_SPLIT_NO_EMPTY)
    );

    $customeradd['info'] = ConfigHelper::getConfig('customers.default_info', '', true);
    $customeradd['notes'] = ConfigHelper::getConfig('customers.default_notes', '', true);
    $customeradd['message'] = ConfigHelper::getConfig('customers.default_message', '', true);
    $customeradd['documentmemo'] = ConfigHelper::getConfig('customers.default_documentmemo', '', true);

    $customeradd['group'] = array();
    if ($groups_required && !preg_match('/^(1|y|on|yes|true|tak|t|enabled)$/i', $groups)) {
        $all_groups = Utils::array_column(
            array_map(
                function ($group) {
                    return array(
                        'id' => $group['id'],
                        'name' => mb_strtolower($group['name']),
                    );
                },
                $all_groups
            ),
            'id',
            'name'
        );
        $customeradd['group'] = array_map(
            function ($groupname) use ($all_groups) {
                return $all_groups[$groupname];
            },
            array_filter(
                preg_split('/([\s]+|[\s]*,[\s]*)/', mb_strtolower($groups)),
                function ($groupname) use ($all_groups) {
                    return isset($all_groups[$groupname]);
                }
            )
        );
    }
}

$customeradd['default-consents'] = $default_consents;

if (!isset($customeradd['cutoffstopindefinitely'])) {
    $customeradd['cutoffstopindefinitely'] = 0;
}

$layout['pagetitle'] = trans('New Customer');

$LMS->InitXajax();

$hook_data = $LMS->executeHook(
    'customeradd_before_display',
    array(
        'customeradd' => $customeradd,
        'smarty' => $SMARTY
    )
);
$customeradd = $hook_data['customeradd'];

$default_states = array();
foreach (array(BILLING_ADDRESS, POSTAL_ADDRESS, LOCATION_ADDRESS) as $addressType) {
    switch ($addressType) {
        case BILLING_ADDRESS:
            $variable_name = 'customers.default_billing_address_state';
            $variable_name_compat = 'phpui.default_billing_address_state';
            break;
        case POSTAL_ADDRESS:
            $variable_name = 'customers.default_postal_address_state';
            $variable_name_compat = 'phpui.default_postal_address_state';
            break;
        case LOCATION_ADDRESS:
            $variable_name = 'customers.default_location_address_state';
            $variable_name_compat = 'phpui.default_location_address_state';
            break;
    }
    if (isset($variable_name)) {
        $default_state = ConfigHelper::getConfig(
            $variable_name,
            ConfigHelper::getConfig(
                $variable_name_compat,
                ConfigHelper::getConfig('phpui.default_address_state')
            )
        );
    } else {
        $default_state = ConfigHelper::getConfig('phpui.default_address_state');
    }
    if (empty($default_state)) {
        $default_states[$addressType] = '';
    } else {
        $default_states[$addressType] = $LMS->getCountryStateIdByName($default_state) ? $default_state : '';
    }
}

$SMARTY->assign('xajax', $LMS->RunXajax());
$SMARTY->assign($LMS->getCustomerPinRequirements());

$SMARTY->assign('default_states', $default_states);
$SMARTY->assign('legal_person_required_properties', $legal_person_required_properties);
$SMARTY->assign('natural_person_required_properties', $natural_person_required_properties);
$SMARTY->assign('legal_person_required_property_validation_error', $legal_person_required_property_validation_error);
$SMARTY->assign('natural_person_required_property_validation_error', $natural_person_required_property_validation_error);
$SMARTY->assign('legal_person_required_property_validation_customer_statuses', $legal_person_required_property_validation_customer_statuses);
$SMARTY->assign('natural_person_required_property_validation_customer_statuses', $natural_person_required_property_validation_customer_statuses);
$SMARTY->assign('divisions', $LMS->GetDivisions(array('userid' => Auth::GetCurrentUser())));
$SMARTY->assign('customeradd', $customeradd);

$SMARTY->display('customer/customeradd.html');
