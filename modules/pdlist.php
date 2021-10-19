<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2020 LMS Developers
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

$action = $_GET['action'];
$id = $_GET['id'];

$addpd = $_POST['addpd'];
$layout['pagetitle'] = trans('Purchase document list');

$params['orderby'] = $_GET['orderby'];

$pdlist = $LMS->GetPurchaseDocumentList($params);

switch ($action) {
    case 'add':
            $LMS->AddPurchaseDocument($addpd);
            $SESSION->redirect('?m=pdlist');
        break;
    case 'modify':
        $pdinfo = $LMS->GetPurchaseDocumentInfo($id);
        $SMARTY->assign('pdinfo', $pdinfo);
        if (isset($pdinfo)) {
            $addpd['id'] = $id;
            $LMS->UpdatePurchaseDocument($addpd);
            $SESSION->redirect('?m=pdlist');
        }
        break;
    case 'delete':
        if (!empty($id)) {
            $LMS->DeletePurchaseDocument($id);
            $SESSION->redirect('?m=pdlist');
        }
        break;
    default:
        break;
}
$SMARTY->assign('action', $action);
$SMARTY->assign('pdlist', $pdlist);
$SMARTY->assign('pagetitle', $layout['pagetitle']);
$SMARTY->display('pd/pdlist.html');
