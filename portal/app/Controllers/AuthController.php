<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\View;

class AuthController
{
    public static function loginForm(): void
    {
        if (Auth::check()) {
            self::sendHome();
        }
        View::render('auth/login', ['title' => 'Login', 'error' => $_GET['error'] ?? null]);
    }

    public static function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
            redirect('login', ['error' => 'Invalid submission.']);
        }
        $id = (string)($_POST['identifier'] ?? '');
        $pw = (string)($_POST['password'] ?? '');
        if (!Auth::attempt($id, $pw)) {
            redirect('login', ['error' => 'Invalid credentials.']);
        }
        self::sendHome();
    }

    public static function logout(): void
    {
        Auth::logout();
        redirect('home');
    }

    private static function sendHome(): void
    {
        redirect('home');
    }

    /** Account settings for the logged-in user (self-service profile). */
    public static function account(): void
    {
        Auth::requireLogin();
        View::render('auth/account', [
            'title'  => 'My account',
            'user'   => Auth::user(),
            'dbUser' => Auth::dbUser(),
            'saved'  => isset($_GET['saved']),
            'error'  => $_GET['error'] ?? null,
        ]);
    }

    public static function accountSave(): void
    {
        Auth::requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
            redirect('account', ['error' => 'Invalid submission.']);
        }
        // Superuser profile is config-managed and cannot be edited here.
        if (Auth::id() === 0) {
            redirect('account', ['error' => 'The superuser profile is managed in config.php.']);
        }
        $pdo = Database::pdo();
        $fields = ['first_name', 'last_name', 'fin_first_name', 'fin_last_name', 'phone',
                   'office_address', 'delivery_address', 'company_website'];
        $sets = [];
        $vals = [];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $sets[] = "$f = ?";
                $vals[] = trim((string)$_POST[$f]);
            }
        }
        if (array_key_exists('preferred_currency', $_POST) && Auth::isStaffOrAdmin()) {
            $pref = strtoupper(trim((string)$_POST['preferred_currency']));
            if ($pref === '' || isset(\App\Currency::codes()[$pref])) {
                $sets[] = 'preferred_currency = ?';
                $vals[] = $pref;
            }
        }
        $newPw = (string)($_POST['password'] ?? '');
        if ($newPw !== '') {
            if (strlen($newPw) < 8) {
                redirect('account', ['error' => 'Password must be at least 8 characters.']);
            }
            $sets[] = 'password_hash = ?';
            $vals[] = password_hash($newPw, PASSWORD_BCRYPT);
        }
        if ($sets) {
            $sets[] = "updated_at = datetime('now')";
            $vals[] = Auth::id();
            $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        }
        redirect('account', ['saved' => 1]);
    }
}
