<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2017 LMS Developers
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
 */

$this->BeginTrans();

$ident_lengths = array(
	'location_states' => 2,
	'location_districts' => 2,
	'location_boroughs' => 2,
	'location_cities' => 7,
	'location_streets' => 5,
);

foreach ($ident_lengths as $table => $length) {
	$records = $this->GetAllByKey("SELECT id, ident FROM $table WHERE LENGTH(ident) < ?",
		'id', array($length));
	if (empty($records))
		continue;
	foreach ($records as $id => $record)
		$this->Execute("UPDATE $table SET ident = ? WHERE id = ?",
			array(sprintf($sprintf, $record['ident']), $id));
}

$this->Execute("UPDATE dbinfo SET keyvalue = ? WHERE keytype = ?", array('2017051203', 'dbversion'));

$this->CommitTrans();

?>
