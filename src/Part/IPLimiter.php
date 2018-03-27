<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-29
 * Time: 9:07
 */

namespace Inhere\WebSocket\Part;

/**
 * Class IPLimiter
 * @package Inhere\WebSocket\Part
 */
class IPLimiter
{
    /**
     * the IP blacklists
     * @var array
     */
    private $blacklists = [];

    /**
     * the IP whitelists
     * @var array
     */
    private $whitelists = [];

    /**
     * @param $ip
     * @return bool
     */
    public function isAllow($ip): bool
    {
        $long = sprintf('%u', ip2long($ip));

        // if 'whitelists' exists, only check it.
        if ($this->whitelists) {
            return \in_array($long, $this->whitelists, true);
        }

        if (\in_array($long, $this->blacklists, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $ip
     * @param bool $fuzzyMatch
     */
    public function addWhite(string $ip, $fuzzyMatch = false)
    {
        // $long = ip2long($ip);
        // to unsigned
        $long = sprintf('%u', ip2long($ip));
        $this->whitelists[] = $long;
    }

    public function addBlack(string $ip, $fuzzyMatch = false)
    {
        // to unsigned
        $long = sprintf('%u', ip2long($ip));
        $this->blacklists[] = $long;
    }

    /**
     * @param $long
     * @return string
     */
    public function long2ip($long): string
    {
        $long = 4294967295 - ($long - 1);

        return long2ip(-$long);
    }

    /**
     * @return array
     */
    public function getBlacklists(): array
    {
        return $this->blacklists;
    }

    /**
     * @param array $blacklists
     */
    public function setBlacklists(array $blacklists)
    {
        $this->blacklists = $blacklists;
    }

    /**
     * @return array
     */
    public function getWhitelists(): array
    {
        return $this->whitelists;
    }

    /**
     * @param array $whitelists
     */
    public function setWhitelists(array $whitelists)
    {
        $this->whitelists = $whitelists;
    }


}
