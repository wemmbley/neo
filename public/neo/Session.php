<?php

declare(strict_types=1);

namespace App\Neo;

class Session
{
    /**
     * Put new value into session driver.
     *
     * <code>
     *      Session::put('login', 'username', 60);
     * </code>
     *
     * @param string $key
     * @param string $value
     * @param int $minutes
     * @return void
     */
    public static function add(string $key, string $value, int $minutes): void
    {
        setcookie($key, $value, static::expire($minutes), '/');
    }

    /**
     * Get value from session driver.
     *
     * <code>
     *      Session::get('login');
     * </code>
     *
     * @param string $key
     * @return string
     */
    public static function get(string $key): string
    {
        return (isset($_COOKIE[$key])) ? $_COOKIE[$key] : '';
    }

    /**
     * Check, if session driver has value.
     *
     * <code>
     *      Session::has('login');
     * </code>
     *
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        if (isset($_COOKIE[$key]))
            return true;
        else return false;
    }

    /**
     * Delete value from session driver.
     *
     * <code>
     *      Session::remove('login');
     * </code>
     *
     * @param string $key
     * @return void
     */
    public static function remove(string $key): void
    {
        unset($_COOKIE[$key]);
    }

    /**
     * Convert minutes to UNIX timestamp.
     *
     * @param int $minutes
     * @return int
     */
    protected static function expire(int $minutes): int
    {
        return (int) (time() + ($minutes * 60));
    }
}