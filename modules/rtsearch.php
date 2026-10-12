<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2022 LMS Developers
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

function rtQueueSearchParams($search, $order)
{
    $params = [
        'order' => $order,
        'withMessageId' => true,
        'parseRequestor' => true,
    ];

    if (!empty($search['operator']) && $search['operator'] == 'OR') {
        $params['operator'] = 'OR';
    }
    if (!empty($search['owner']) && intval($search['owner'])) {
        $params['owner'] = intval($search['owner']);
    }
    if (!empty($search['custid'])) {
        $params['cid'] = intval($search['custid']);
    }
    if (!empty($search['subject'])) {
        $params['subject'] = $search['subject'];
    }
    if (isset($search['body'])) {
        $params['body'] = $search['body'];
    }
    if (isset($search['state']) && strlen((string) $search['state']) && (string) $search['state'] !== '-2') {
        $params['state'] = (string) $search['state'] === '0' ? [0] : $search['state'];
    }
    if (isset($search['source']) && strlen((string) $search['source'])) {
        $source = intval($search['source']);
        if ($source === 0 || $source === -1) {
            $params['sourceExact'] = $source;
        } else {
            $params['source'] = $source;
        }
    }
    if (!empty($search['priority'])) {
        $params['priority'] = $search['priority'] == '-101' ? ['-101'] : intval($search['priority']);
    }
    foreach (['email', 'phone', 'name', 'uptime', 'address', 'zip', 'city'] as $key) {
        if (!empty($search[$key])) {
            $params[$key] = $search[$key];
        }
    }
    if (!empty($search['queue'])) {
        $params['queue'] = $search['queue'];
    }
    if (!empty($search['catids'])) {
        $params['catids'] = $search['catids'];
    }
    if (!empty($search['removed']) && ($search['removed'] == '-1' || $search['removed'] == '1')) {
        $params['removed'] = $search['removed'];
    }
    if (!empty($search['netnodeid'])) {
        $params['netnodeids'] = intval($search['netnodeid']);
    }
    if (!empty($search['netdevid'])) {
        $params['netdevids'] = intval($search['netdevid']);
    }
    if (!empty($search['verifierid'])) {
        $params['verifierids'] = intval($search['verifierid']);
    }
    if (!empty($search['expired'])) {
        $params['deadline'] = -2;
    }
    if (!empty($search['service'])) {
        $params['serviceids'] = intval($search['service']);
    }
    if (!empty($search['type'])) {
        $params['typeids'] = intval($search['type']);
    }

    return $params;
}

$categories = $LMS->GetUserCategories(Auth::GetCurrentUser());

$layout['pagetitle'] = trans('Ticket Search');

if (isset($_POST['search'])) {
    $search = $_POST['search'];
} elseif (isset($_GET['page']) || isset($_GET['o']) || isset($_GET['quicksearch'])) {
    $SESSION->restore('rtsearch', $search);
}

if (isset($_GET['id'])) {
    $search['custid'] = $_GET['id'];
    $search['queue'] = null;
}

if (isset($_GET['state'])) {
    $search = array(
        'state' => $_GET['state'],
        'subject' => '',
        'body' => array(
            'message' => '',
            'regexp' => false,
        ),
        'custid' => '0',
        'address' => '',
        'zip' => '',
        'city' => '',
        'name' => '',
        'email' => '',
        'owner' => '0',
        'queue' => null,
        'uptime' => '',
        'catids' => null
    );
}

if (!isset($_GET['o'])) {
    $SESSION->restore('rto', $o);
} else {
    $o = $_GET['o'];
}

$SESSION->save('rto', $o);

if ($SESSION->is_set('rtp') && !isset($_GET['page']) && !isset($search)) {
    $SESSION->restore('rtp', $_GET['page']);
}

if (isset($search) || isset($_GET['s'])) {
    if (!empty($search['queue'])) {
        if (is_array($search['queue'])) {
            foreach ($search['queue'] as $queue) {
                if (!$LMS->GetUserRightsRT(Auth::GetCurrentUser(), $queue)) {
                    $error['queue'] = trans('You have no privileges to review this queue!');
                }
            }
        }
    }

    if (!isset($search['categories'])) {
        $search['catids'] = null;
    } else {
        $search['catids'] = $search['categories'];
    }

    if (!$error) {
        $search['count'] = true;
        $queueParams = rtQueueSearchParams($search, $o);
        $queueParams['count'] = true;
        $search['total'] = intval($LMS->GetQueueContents($queueParams));

        $search['page'] = intval((! isset($_GET['page']) ? 1 : $_GET['page']));
        $search['limit'] = intval(ConfigHelper::getConfig(
            'rt.ticketlist_pagelimit',
            ConfigHelper::getConfig('phpui.ticketlist_pagelimit', $search['total'])
        ));
        $search['offset'] = ($search['page'] - 1) * $search['limit'];

        $search['count'] = false;
        $queueParams['count'] = false;
        $queueParams['limit'] = $search['limit'];
        $queueParams['offset'] = $search['offset'];
        $queue = $LMS->GetQueueContents($queueParams);

        $pagination = LMSPaginationFactory::getPagination(
            $search['page'],
            $search['total'],
            $search['limit'],
            ConfigHelper::checkConfig('phpui.short_pagescroller')
        );

        $search['order'] = $queue['order'];
        $search['direction'] = $queue['direction'];
        $search['queue'] = $search['queue'] ?? 0;

        unset(
            $queue['total'],
            $queue['state'],
            $queue['order'],
            $queue['direction'],
            $queue['owner'],
            $queue['removed'],
            $queue['priority'],
            $queue['source'],
            $queue['deadline'],
            $queue['service'],
            $queue['type'],
            $queue['unread'],
            $queue['rights'],
            $queue['fromdate'],
            $queue['todate'],
            $queue['watching']
        );

        $SESSION->save('rtp', $page ?? 0);
        $SESSION->save('rtsearch', $search);

        $SMARTY->assign('pagination', $pagination);
        $SMARTY->assign('queue', $queue);
        $SMARTY->assign('filter', $search);
        $SMARTY->display('rt/rtsearchresults.html');
        $SESSION->close();
        die;
    }
} else {
    if (!empty($categories)) {
        foreach ($categories as $category) {
            $category['checked'] = true;
            $ncategories[] = $category;
        }
    }
    $categories = $ncategories;
}

function netnode_changed($netnodeid, $netdevid)
{
    global $LMS, $SMARTY;

    $JSResponse = new xajaxResponse();

    $search = array();
    if (!empty($netnodeid)) {
        $search['netnode'] = $netnodeid;
    }
    $netdevlist = $LMS->GetNetDevList('name', $search);
    unset($netdevlist['total'], $netdevlist['order'], $netdevlist['direction']);

    $SMARTY->assign('netdevlist', $netdevlist);
    $SMARTY->assign('ticket', array('netdevid' => $netdevid));
    $SMARTY->assign('form', 'search');
    $content = $SMARTY->fetch('rt' . DIRECTORY_SEPARATOR . 'rtnetdevs.html');
    $JSResponse->assign('rtnetdevs', 'innerHTML', $content);

    return $JSResponse;
}

$LMS->InitXajax();
$LMS->RegisterXajaxFunction('netnode_changed');
$SMARTY->assign('xajax', $LMS->RunXajax());

$SESSION->add_history_entry();

$netnodelist = $LMS->GetNetNodeList();
unset($netnodelist['total'], $netnodelist['order'], $netnodelist['direction']);

$netdevlist = $LMS->GetNetDevList();
unset($netdevlist['total'], $netdevlist['order'], $netdevlist['direction']);

$SMARTY->assign('queuelist', $LMS->GetQueueList(array('stats' => false)));
$SMARTY->assign('categories', $categories);
$SMARTY->assign('netnodelist', $netnodelist);
$SMARTY->assign('netdevlist', $netdevlist);
$SMARTY->assign('userlist', $LMS->GetUserNames());
$SMARTY->assign('customerlist', $LMS->GetAllCustomerNames());
$SMARTY->assign('search', $search ?? null);
$SMARTY->assign('error', $error);
$SMARTY->display('rt/rtsearch.html');
