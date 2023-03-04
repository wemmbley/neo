<?php

namespace App\Neo;

use App\Neo\Session;

class Protector
{
    /**
     * Make string safer.
     *
     * @param string $str
     * @return string
     */
    public static function str(string $str): string
    {
        return htmlspecialchars($str);
    }

    /**
     * Output csrf hidden input with echo.
     *
     * @return void
     * @throws Exception
     */
    public static function csrf(): void
    {
        if (Session::has('csrf'))
            Session::add('csrf', bin2hex(random_bytes(50)), 60*24);

        echo '<input type="hidden" name="csrf" value="' . Session::get('csrf') . '">';
    }

    /**
     * Check, if csrf correct.
     *
     * @return bool
     */
    public static function isCsrf(): bool
    {
        if (Session::has('csrf') || !isset($_POST['csrf'])) {
            return false;
        }

        if (Session::get('csrf') != $_POST['csrf']) {
            return false;
        }

        return true;
    }
}