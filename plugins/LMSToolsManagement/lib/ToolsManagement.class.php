<?php
class ToolsManagement
{
    private $db;

    public function __construct()
    {
    	$this->db = LMSDB::getInstance();
    }

    public function setConfirmationFlag($ids, bool $state) : void
    {
        return true;
    }
}
