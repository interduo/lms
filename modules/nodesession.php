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

header('Content-type: application/json');

if (!isset($_GET['action']) || !isset($_GET['id'])) {
    die('[]');
}

switch ($_GET['action']) {
    case 'disconnect':
        $nodesession = $LMS->getRadiusParams(array(
            'nodesessionid' => $_GET['id'],
        ));
        if (empty($nodesession['secret'])) {
            die(json_encode(array(
                'error' => trans('Couldn\'t determine RADIUS secret value!'),
            )));
        }

        $cmd = ConfigHelper::getConfig('phpui.radius_disconnect_command', 'echo Framed-IP-Address=%ip% | radclient %nasip%:3799 disconnect %secret%');
        if (!check_ip($nodesession['ip']) || !check_ip($nodesession['nasip'])) {
            die(json_encode(array(
                'error' => trans('RADIUS disconnect failed!'),
            )));
        }
        $sessionArg = escapeshellarg((string) $nodesession['nassessionid']);
        $secretArg = escapeshellarg((string) $nodesession['secret']);
        $cmd = strtr($cmd, array(
            '"%nassessionid%"' => $sessionArg,
            "'%nassessionid%'" => $sessionArg,
            '%nassessionid%' => $sessionArg,
            '"%secret%"' => $secretArg,
            "'%secret%'" => $secretArg,
            '%secret%' => $secretArg,
            '%ip%' => $nodesession['ip'],
            '%nasip%' => $nodesession['nasip'],
        ));
        $result = null;
        $output = null;
        exec($cmd, $output, $result);

        if (!empty($result)) {
            die(json_encode(array(
                'error' => trans('RADIUS disconnect failed!'),
            )));
        }

        break;
}

die('[]');
