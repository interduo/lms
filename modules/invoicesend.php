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

use \Lms\KSeF\KSeF;

function invoiceSendCollectRecipients($customerId, $requested, $extra)
{
    global $LMS;

    $allowed = [];
    foreach ($LMS->GetCustomerContacts($customerId, CONTACT_EMAIL) as $contact) {
        if (($contact['type'] & CONTACT_DISABLED) == CONTACT_DISABLED) {
            continue;
        }
        $email = trim($contact['contact']);
        if ($email === '') {
            continue;
        }
        $allowed[strtolower($email)] = $email;
    }

    if (!is_array($requested)) {
        $requested = ($requested === null || $requested === '') ? [] : [$requested];
    }

    $chosen = [];
    foreach ($requested as $email) {
        $email = trim((string) $email);
        if ($email === '') {
            continue;
        }
        $key = strtolower($email);
        if (!isset($allowed[$key])) {
            return false;
        }
        $chosen[$key] = $allowed[$key];
    }

    $extra = trim((string) $extra);
    if ($extra !== '') {
        $key = strtolower($extra);
        if (isset($allowed[$key])) {
            $chosen[$key] = $allowed[$key];
        } elseif (!check_email($extra)) {
            return false;
        } elseif (!isset($chosen[$key])) {
            $chosen[$key] = $extra;
        }
    }

    return array_values($chosen);
}

function invoiceSendTemplateBody($divisionId)
{
    ConfigHelper::setFilter($divisionId);
    $body = ConfigHelper::getConfig('sendinvoices.mail_body', ConfigHelper::getConfig('mail.sendinvoice_mail_body'));
    if (!is_string($body)) {
        return '';
    }
    if ($body !== '' && $body[0] === DIRECTORY_SEPARATOR && is_readable($body)) {
        $contents = file_get_contents($body);
        if ($contents !== false) {
            $body = $contents;
        }
    }
    return $body;
}

function invoiceSendSanitizeBody($body, $format)
{
    if ($format != 'html') {
        return $body;
    }
    $eol = 'LMS_INVOICE_EOL_PLACEHOLDER';
    return str_replace($eol, '<eol>', Utils::removeInsecureHtml(str_replace('<eol>', $eol, $body)));
}

if (isset($_GET['action']) && $_GET['action'] == 'get-recipients') {
    header('Content-Type: application/json');

    $docId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $customerId = $docId ? $LMS->getDocumentCustomerId($docId) : null;
    $docType = $docId ? (int) $LMS->getDocumentType($docId) : 0;
    $sendableTypes = [DOC_INVOICE, DOC_CNOTE, DOC_INVOICE_PRO, DOC_DNOTE];

    if (empty($customerId) || !in_array($docType, $sendableTypes, true)) {
        die(json_encode(['error' => trans('Access denied!')]));
    }

    if (isset($_GET['customerid']) && $_GET['customerid'] !== '' && intval($_GET['customerid']) !== intval($customerId)) {
        die(json_encode(['error' => trans('Access denied!')]));
    }

    if (empty($LMS->GetCustomer($customerId, true))) {
        die(json_encode(['error' => trans('Access denied!')]));
    }

    $contacts = [];
    foreach ($LMS->GetCustomerContacts($customerId, CONTACT_EMAIL) as $contact) {
        if (($contact['type'] & CONTACT_DISABLED) == CONTACT_DISABLED) {
            continue;
        }
        $email = trim($contact['contact']);
        if ($email === '') {
            continue;
        }
        $contacts[] = [
            'email' => $email,
            'name' => (string) $contact['name'],
            'checked' => ($contact['type'] & CONTACT_INVOICES) == CONTACT_INVOICES,
        ];
    }

    $divisionId = $DB->GetOne('SELECT divisionid FROM documents WHERE id = ?', [$docId]);

    die(json_encode(['contacts' => $contacts, 'body' => invoiceSendTemplateBody($divisionId ?: null)]));
}

$layout['pagetitle'] = trans('Invoice send');

$SMARTY->display('header.html');

if (!isset($_GET['sent']) && isset($_SERVER['HTTP_REFERER']) && !preg_match('/m=invoicesend/', $_SERVER['HTTP_REFERER'])) {
    set_time_limit(0);

    echo '<H1>' . $layout['pagetitle'] . '</H1>';

    if (isset($_POST['marks'])) {
        if (!empty($_POST['marks']['invoice']) || !empty($_POST['marks']['note'])) {
            $marks = [];
            if (!empty($_POST['marks']['invoice'])) {
                $marks = array_merge($marks, $_POST['marks']['invoice']);
            }
            if (!empty($_POST['marks']['note'])) {
                $marks = array_merge($marks, $_POST['marks']['note']);
            }
        } else {
            if ($_GET['marks'] == 'invoice' || $_GET['marks'] == 'note' || $_GET['marks'] == 'cash') {
                $marks = $_POST['marks'];
            }
        }

        $ids = Utils::filterIntegers($marks);

        if ($_GET['marks'] == 'cash' && !empty($ids)) {
            $ids = $LMS->GetDocumentsForBalanceRecords($ids, array(DOC_INVOICE, DOC_CNOTE, DOC_INVOICE_PRO, DOC_DNOTE));
        }
    } elseif (isset($_GET['id']) && intval($_GET['id'])) {
        $ids = array(intval($_GET['id']));
    } else {
        $ids = array();
    }

    if (empty($ids)) {
        echo '<span class="red">' . trans("Fatal error: No invoices nor debit notes were selected!") . '</span><br>';
    } else {
        $docs = $DB->GetAll(
            "SELECT
                d.id,
                d.number,
                d.cdate,
                d.name,
                d.customerid,
                d.type AS doctype,
                d.archived,
                n.template,
                m.email,
                d.divisionid,
                d.div_name,
                d.div_shortname,
                d.div_account,
                (CASE WHEN EXISTS (SELECT 1 FROM documents d2 WHERE d2.reference = d.id AND d2.type < 0) THEN 1 ELSE 0 END) AS documentreferenced,
                kd.ksefnumber,
                kd.status AS ksefstatus,
                kd.hash AS ksefhash,
                kbs.environment AS ksefenvironment,
                d.div_ten AS kseften
            FROM documents d
            LEFT JOIN customers c ON c.id = d.customerid
            LEFT JOIN ksefdocuments kd ON kd.docid = d.id AND kd.status IN ?
            LEFT JOIN ksefbatchsessions kbs ON kbs.id = kd.batchsessionid
            LEFT JOIN ksefconfig kc ON kc.divisionid = d.divisionid
            JOIN (
                SELECT customerid, " . $DB->GroupConcat('contact') . " AS email
                FROM customercontacts
                WHERE (type & ?) = ?
                GROUP BY customerid
            ) m ON m.customerid = c.id
            LEFT JOIN numberplans n ON n.id = d.numberplanid
            WHERE (
                    d.type IN ?
                    AND (
                        d.cdate < kc.boundarydate
                        OR kc.boundarydate IS NULL
                        OR c.type = ? AND (COALESCE(kc.allconsumers, 0) = ? AND NOT EXISTS (SELECT 1 FROM customerconsents cc WHERE cc.customerid = d.customerid AND cc.type = ?) OR kd.status IS NOT NULL)
                        OR c.type = ? AND kd.status IS NOT NULL
                    ) OR d.type IN ?
                ) AND d.id IN (" . implode(',', $ids) . ")
            ORDER BY d.number",
            [
                [
                    0,
                    200,
                ],
                CONTACT_EMAIL | CONTACT_INVOICES | CONTACT_DISABLED, CONTACT_EMAIL | CONTACT_INVOICES,
                [
                    DOC_INVOICE,
                    DOC_CNOTE,
                ],
                CTYPES_PRIVATE,
                0,
                CCONSENT_KSEF_INVOICE,
                CTYPES_COMPANY,
                [
                    DOC_DNOTE,
                    DOC_INVOICE_PRO,
                ],
            ]
        );

        if (!empty($docs)) {
            $singleSend = isset($_GET['id']) && count($ids) === 1;
            $postedMailBody = ($singleSend && isset($_POST['body'])) ? (string) $_POST['body'] : null;
            if ($singleSend && (isset($_GET['recipients']) || isset($_GET['extra']))) {
                $customerId = (int) $docs[0]['customerid'];
                if (empty($LMS->GetCustomer($customerId, true))) {
                    echo '<span class="red">' . trans('Access denied!') . '</span><br>';
                    $docs = [];
                } else {
                    $chosenEmails = invoiceSendCollectRecipients(
                        $customerId,
                        $_GET['recipients'] ?? [],
                        $_GET['extra'] ?? ''
                    );
                    if ($chosenEmails === false) {
                        echo '<span class="red">' . trans('Incorrect email address!') . '</span><br>';
                        $docs = [];
                    } elseif (empty($chosenEmails)) {
                        echo '<span class="red">' . trans('Unable to send message. No recipients selected!') . '</span><br>';
                        $docs = [];
                    } else {
                        foreach ($docs as &$doc) {
                            $doc['email'] = implode(',', $chosenEmails);
                        }
                        unset($doc);
                    }
                }
            }

            $which = isset($_GET['which']) ? intval($_GET['which']) : 0;
            if ($which & DOC_ENTITY_DUPLICATE) {
                $duplicate_date = isset($_GET['duplicate-date']) ? intval($_GET['duplicate-date']) : 0;
            } else {
                $duplicate_date = 0;
            }
            if (!$which) {
                $which = DOC_ENTITY_ORIGINAL;
            }

            $currtime = time();

            $divisiondocs = array();
            foreach ($docs as $doc) {
                $divisionid = $doc['divisionid'];
                if (!isset($divisiondocs[$divisionid])) {
                    $divisiondocs[$divisionid] = array();
                }
                $divisiondocs[$divisionid][] = $doc;
            }

            foreach ($divisiondocs as $divisionid => $docs) {
                ConfigHelper::setFilter($divisionid);

                $ksefOfflineSupport = ConfigHelper::checkConfig('ksef.offline_support');
                $docs = array_filter(
                    $docs,
                    function ($doc) use ($ksefOfflineSupport) {
                        return empty($doc['ksefhash']) || $doc['ksefstatus'] == 200 || $ksefOfflineSupport && empty($doc['ksefstatus']);
                    }
                );

                $smtp_options = array(
                    'host' => ConfigHelper::getConfig('sendinvoices.smtp_host'),
                    'port' => ConfigHelper::getConfig('sendinvoices.smtp_port'),
                    'user' => ConfigHelper::getConfig('sendinvoices.smtp_username', ConfigHelper::getConfig('sendinvoices.smtp_user')),
                    'pass' => ConfigHelper::getConfig('sendinvoices.smtp_password', ConfigHelper::getConfig('sendinvoices.smtp_pass')),
                    'auth' => ConfigHelper::getConfig('sendinvoices.smtp_auth_type', ConfigHelper::getConfig('sendinvoices.smtp_auth')),
                    'ssl_verify_peer' => ConfigHelper::checkConfig('sendinvoices.smtp_ssl_verify_peer', true),
                    'ssl_verify_peer_name' => ConfigHelper::checkConfig('sendinvoices.smtp_ssl_verify_peer_name', true),
                    'ssl_allow_self_signed' => ConfigHelper::checkConfig('sendinvoices.smtp_ssl_allow_self_signed'),
                );

                $debug_email = ConfigHelper::getConfig('sendinvoices.debug_email', '', true);
                $sender_name = ConfigHelper::getConfig('sendinvoices.sender_name', '', true);
                $sender_email = ConfigHelper::getConfig('sendinvoices.sender_email', '', true);
                $mail_subject = ConfigHelper::getConfig('sendinvoices.mail_subject', 'Invoice No. %invoice');
                $mail_body = ConfigHelper::getConfig('sendinvoices.mail_body', ConfigHelper::getConfig('mail.sendinvoice_mail_body'));
                $mail_format = ConfigHelper::getConfig('sendinvoices.mail_format', 'text');
                $mail_body_raw = false;
                if ($postedMailBody !== null) {
                    $mail_body = invoiceSendSanitizeBody($postedMailBody, $mail_format);
                    $mail_body_raw = true;
                }
                $invoice_filename = ConfigHelper::getConfig('sendinvoices.invoice_filename', 'invoice_%docid');
                $dnote_filename = ConfigHelper::getConfig('sendinvoices.debitnote_filename', 'dnote_%docid');
                $notify_email = ConfigHelper::getConfig('sendinvoices.notify_email', '', true);
                $reply_email = ConfigHelper::getConfig('sendinvoices.reply_email', '', true);
                $add_message = ConfigHelper::checkConfig('sendinvoices.add_message');
                $message_attachments = ConfigHelper::checkConfig('sendinvoices.message_attachments');
                $aggregate_documents = ConfigHelper::checkConfig('sendinvoices.aggregate_documents');
                $financial_history_reverse_order = ConfigHelper::checkConfig(
                    'sendinvoices.financial_history_reverse_order',
                    ConfigHelper::checkConfig(
                        'finances.history_reverse_order',
                        true
                    )
                );
                $financial_history_item_description_format = ConfigHelper::getConfig(
                    'sendinvoices.financial_history_item_description_format',
                    ConfigHelper::getConfig(
                        'finances.history_item_description_format',
                        '%comment'
                    )
                );
                $dsn_email = ConfigHelper::getConfig('sendinvoices.dsn_email', '', true);
                $mdn_email = ConfigHelper::getConfig('sendinvoices.mdn_email', '', true);

                $extrafile = ConfigHelper::getConfig('sendinvoices.extra_file', null, true);
                if ($extrafile && !is_readable($extrafile)) {
                    $extrafile = null;
                }

                if (empty($sender_email)) {
                    echo '<span class="red">' . trans("Fatal error: sender_email unset! Can't continue, exiting.") . '</span><br>';
                }

                $smtp_auth = empty($smtp_auth) ? ConfigHelper::getConfig('mail.smtp_auth_type') : $smtp_auth;
                if (!empty($smtp_auth) && !preg_match('/^LOGIN|PLAIN|CRAM-MD5|NTLM$/i', $smtp_auth)) {
                    echo '<span class="red">' . trans("Fatal error: smtp_auth value not supported! Can't continue, exiting.") . '</span><br>';
                }

                $quiet = false;
                $test = false;

                $LMS->SendInvoices(
                    $docs,
                    'frontend',
                    compact(
                        'SMARTY',
                        'invoice_filename',
                        'dnote_filename',
                        'debug_email',
                        'mail_body',
                        'mail_body_raw',
                        'mail_subject',
                        'mail_format',
                        'currtime',
                        'sender_email',
                        'sender_name',
                        'extrafile',
                        'dsn_email',
                        'reply_email',
                        'mdn_email',
                        'notify_email',
                        'quiet',
                        'test',
                        'add_message',
                        'message_attachments',
                        'aggregate_documents',
                        'financial_history_reverse_order',
                        'financial_history_item_description_format',
                        'which',
                        'duplicate_date',
                        'smtp_options'
                    )
                );
            }
        }
    }

    echo '<script type="text/javascript">';
    echo "history.replaceState({}, '', location.href.replace(/&(is_sure|sent)=1/gi, '') + '&sent=1');";
    echo '</script>';
}

$SMARTY->display('footer.html');
