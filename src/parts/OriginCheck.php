<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-08-17
 * Time: 11:30
 */

namespace inhere\webSocket\parts;

use Inhere\Http\Request;

/**
 * Class OriginCheck
 * @package inhere\webSocket\parts
 */
class OriginCheck
{
    /**
     * @var array
     */
    private $allowedOrigins = [];

    /**
     * OriginCheck constructor.
     * @param array $allowed
     */
    public function __construct(array $allowed = [])
    {
        $this->allowedOrigins = array_merge($this->allowedOrigins, $allowed);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function check($request)
    {
        $header = $request->getHeader('Origin');
        $origin = parse_url($header, PHP_URL_HOST) ?: $header;

        return in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * @return array
     */
    public function getAllowedOrigins(): array
    {
        return $this->allowedOrigins;
    }
}
