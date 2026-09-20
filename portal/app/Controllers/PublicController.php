<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\RestockOrders;
use App\Settings;
use App\View;

class PublicController
{
    /** Portal home: per-vendor restock reports for signed-in staff/admin. */
    public static function home(): void
    {
        $signedIn = Auth::isStaffOrAdmin();
        View::render('public/home', [
            'title' => $signedIn ? 'Vendor restock reports' : Settings::get('company_name', 'Welcome'),
            'signedIn' => $signedIn,
            'reports' => $signedIn ? RestockOrders::goalReports() : [],
        ]);
    }
}
