<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/4/3 0003
 * Time: 01:05
 */

namespace inhere\webSocket;


use inhere\webSocket\server\BaseWebSocket;

class Helper
{
    public static function encode()
    {

    }


    /**
     * @param $s
     * @return string
     */
    public static function frame($s)
    {
        $a = str_split($s, 125);
        $prefix = BaseWebSocket::BINARY_TYPE_BLOB;

        if (count($a) === 1){
            return $prefix . chr(strlen($a[0])) . $a[0];
        }

        $ns = '';

        foreach ($a as $o){
            $ns .= $prefix . chr(strlen($o)) . $o;
        }

        return $ns;
    }

    /**
     * @param $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        /*$len = $masks = $data =*/ $decoded = '';
        $len = ord($buffer[1]) & 127;

        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }

        $dataLen = strlen($data);
        for ($index = 0; $index < $dataLen; $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }

        return $decoded;
    }

}
