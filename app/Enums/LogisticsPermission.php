<?php

namespace App\Enums;

enum LogisticsPermission: string
{
    case GlobalAccess = 'logistics.global';
    case ManageSites = 'logistics.sites.manage';
    case SuperviseSites = 'logistics.sites.supervise';
    case ViewAssignments = 'logistics.assignments.view';
}
