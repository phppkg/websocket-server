<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-30
 * Time: 19:02
 */

namespace inhere\webSocket\http;

use inhere\library\collections\SimpleCollection;

/**
 * Class Headers
 * @package inhere\webSocket\http
 */
class Headers extends SimpleCollection
{
    /**
     * the connection header line data end char
     */
    const EOL = "\r\n";

    const HEADER_END = "\r\n\r\n";

    /**
     * @inheritdoc
     */
    public function set($key, $value)
    {
        if (!$value) {
            return $this->remove($key);
        }

        return parent::set($this->normalizeKey($key), $value);
    }

    /**
     * @inheritdoc
     */
    public function get($key, $default = null)
    {
        return parent::get($this->normalizeKey($key), $default);
    }

    /**
     * @inheritdoc
     */
    public function add($key, $value)
    {
        if (!$value) {
            return $this;
        }

        return parent::add($this->normalizeKey($key), $value);
    }

    /**
     * @inheritdoc
     */
    public function has(string $key)
    {
        return parent::has($this->normalizeKey($key));
    }

    /**
     * @inheritdoc
     */
    public function remove($key)
    {
        parent::remove($this->normalizeKey($key));
    }

    /**
     * @param $key
     * @return bool|string
     */
    public function normalizeKey($key)
    {
        // $key = str_replace('_', '-', strtolower($key));
        $key = str_replace('_', '-', trim($key));
        $key = ucwords($key, '-');

        if (strpos($key, 'Http-') === 0) {
            $key = substr($key, 5);
        }

        return $key;
    }

    /**
     * get client supported languages from header
     * eg: `Accept-Language:zh-CN,zh;q=0.8`
     * @return array
     */
    public function getAcceptLanguages()
    {
        $ls = [];

        if ( $value = $this->get('Accept-Language') ) {
            if ( strpos($value, ';') ) {
                [$value,] = explode(';', $value,2);
            }

            $value = str_replace(' ', '', $value);
            $ls = explode(',', $value);
        }

        return $ls;
    }

    /**
     * get client supported languages from header
     * eg: `Accept-Encoding:gzip, deflate, sdch, br`
     * @return array
     */
    public function getAcceptEncodes()
    {
        $ens = [];

        if ( $value = $this->get('Accept-Encoding') ) {
            if ( strpos($value, ';') ) {
                [$value,] = explode(';', $value,2);
            }

            $value = str_replace(' ', '', $value);
            $ens = explode(',', $value);
        }

        return $ens;
    }

    /**
     * @param bool $toString
     * @return array
     */
    public function toHeaderLines($toString = false)
    {
        $output = [];

        foreach ($this->data as $name => $value) {
            // $name = ucwords($name, '-');
            $output[] .= "$name: $value" . self::EOL;
        }

        return $toString ? implode('', $output) : $output;
    }
}
