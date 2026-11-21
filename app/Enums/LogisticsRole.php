<?php

namespace App\Enums;

enum LogisticsRole: string
{
    case SuperAdmin = 'Super Admin';
    case Administrator = 'Administrador';
    case Supervisor = 'Supervisor';
    case Carpenter = 'Carpintero';
    case Helper = 'Ayudante';
}
