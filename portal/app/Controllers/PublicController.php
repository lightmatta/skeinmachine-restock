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
            'title' => $signedIn ? 'Reports' : Settings::get('company_name', 'Welcome'),
            'signedIn' => $signedIn,
            'reports' => $signedIn ? RestockOrders::goalReports() : [],
        ]);
    }

    /** Professionally formatted Restock Request Form PDF for one vendor group. */
    public static function reportPdf(): void
    {
        if (!Auth::isStaffOrAdmin()) {
            redirect('login');
        }
        $vendor = (string)($_GET['vendor'] ?? '');
        $q = (string)($_GET['q'] ?? '');
        $report = RestockOrders::findReport($vendor, $q);
        if (!$report) {
            abort(404, 'Report not found.');
        }
        $order = trim((string)($_GET['order'] ?? ''));
        if ($order !== '') {
            $report['lines'] = RestockOrders::orderLines($report['lines'], $order);
        } else {
            $report['lines'] = RestockOrders::sortLines(
                $report['lines'],
                (string)($_GET['sort'] ?? 'stock'),
                (string)($_GET['dir'] ?? 'asc'),
                (string)($_GET['group'] ?? ''),
                (string)($_GET['gdir'] ?? 'asc')
            );
        }
        $pdf = RestockOrders::reportPdf($report);
        $name = RestockOrders::reportFilename($report);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Content-Length: ' . (string)strlen($pdf));
        echo $pdf;
        exit;
    }
}
