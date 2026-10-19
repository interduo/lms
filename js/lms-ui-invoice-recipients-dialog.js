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

function submitInvoiceSend(url, body) {
    var form = $('<form method="post" target="_blank" style="display:none">')
        .attr('action', url)
        .append($('<textarea name="body">').val(body));
    $('body').append(form);
    form.get(0).submit();
    form.remove();
}

function appendInvoiceSendRecipients(url, recipients, extra) {
    var params = [];

    if (!recipients.length) {
        params.push('recipients[]=');
    } else {
        $.each(recipients, function(index, email) {
            params.push('recipients[]=' + encodeURIComponent(email));
        });
    }
    if (extra) {
        params.push('extra=' + encodeURIComponent(extra));
    }

    return url + (url.indexOf('?') === -1 ? '?' : '&') + params.join('&');
}

function invoiceRecipientsDialog(options, context) {
    var deferred = $.Deferred();
    var settled = false;
    var outcome = null;

    context = typeof(context) === 'undefined' ? null : context;
    options = options || {};

    function finish() {
        if (settled) {
            return;
        }
        settled = true;
        if (outcome) {
            if (context) {
                deferred.resolveWith(context, [outcome]);
            } else {
                deferred.resolve(outcome);
            }
        } else if (context) {
            deferred.rejectWith(context);
        } else {
            deferred.reject();
        }
    }

    $.ajax({
        url: '?m=invoicesend&action=get-recipients&id=' + encodeURIComponent(options.docId || '')
            + (options.customerId ? '&customerid=' + encodeURIComponent(options.customerId) : ''),
        dataType: 'json',
        cache: false
    }).done(function(data) {
        if (!data || data.error || !data.contacts) {
            alertDialog(escapeHtml(data && data.error ? data.error : $t('Unable to load invoice recipients.')), context);
            finish();
            return;
        }

        var customerName = options.customerName || '';
        var sendDate = options.sendDate;
        var message;
        if (!sendDate || parseInt(sendDate, 10) === 0) {
            message = $t("Are you sure, you want to send invoice to customer '$a'?", customerName);
        } else {
            message = $t("Invoice was sent to customer '$a' already on $b. Are you sure, you want to send invoice again?", customerName, sendDate);
        }

        var html = '<div class="invoice-recipients"><p>' + escapeHtml(message) + '</p>';
        if (data.contacts.length) {
            html += '<table class="lmsbox lms-ui-background-cycle">';
            $.each(data.contacts, function(index, contact) {
                var label = escapeHtml(contact.email);
                if (contact.name) {
                    label += ' (' + escapeHtml(contact.name) + ')';
                }
                html += '<tr><td><label><input type="checkbox" class="invoice-recipient" value="'
                    + escapeHtml(contact.email) + '"' + (contact.checked ? ' checked' : '') + '> '
                    + label + '</label></td></tr>';
            });
            html += '</table>';
        } else {
            html += '<p>' + escapeHtml($t('There are no e-mail contacts to choose from.')) + '</p>';
        }
        html += '<p><label>' + escapeHtml($t('Additional e-mail address:'))
            + ' <input type="text" class="invoice-recipient-extra" size="40"></label></p>';
        html += '<div>' + escapeHtml($t('Message body:'))
            + '<br><textarea class="invoice-recipient-body lms-ui-dialog-input wf" cols="70" rows="12"></textarea></div>';
        html += '<div class="invoice-recipient-error red" style="display: none;"></div></div>';

        modalDialog($t('Send invoice'), html, [
            {
                text: $t('Send'),
                icon: 'ui-icon-check',
                class: 'lms-ui-button',
                click: function() {
                    var recipients = [];
                    $('#lms-ui-modal-dialog .invoice-recipient:checked').each(function() {
                        recipients.push($(this).val());
                    });
                    var extra = $.trim($('#lms-ui-modal-dialog .invoice-recipient-extra').val());
                    if (!recipients.length && !extra) {
                        $('#lms-ui-modal-dialog .invoice-recipient-error')
                            .text($t('Unable to send message. No recipients selected!'))
                            .show();
                        return;
                    }
                    outcome = {
                        recipients: recipients,
                        extra: extra,
                        body: $('#lms-ui-modal-dialog .invoice-recipient-body').val()
                    };
                    $(this).dialog('close');
                    finish();
                }
            },
            {
                text: $t('Cancel'),
                icon: 'ui-icon-closethick',
                class: 'lms-ui-button',
                click: function() {
                    outcome = null;
                    $(this).dialog('close');
                    finish();
                }
            }
        ], deferred, context);

        $('#lms-ui-modal-dialog .invoice-recipient-body').val(data.body || '');

        $('#lms-ui-modal-dialog').one('dialogclose', function() {
            setTimeout(finish, 0);
        });
    }).fail(function() {
        alertDialog(escapeHtml($t('Unable to load invoice recipients.')), context);
        finish();
    });

    return deferred;
}
