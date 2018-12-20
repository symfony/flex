<?php

namespace Harmony\Flex\Platform;

/**
 * Class Settings
 *
 * @package Harmony\Flex\Platform\Handler
 */
class Settings
{

    /**
     * Build database url formatted like:
     * <type>://[user[:password]@][host][:port][/db][?param_1=value_1&param_2=value_2...]
     *
     * @example mysql://user:password@127.0.0.1/db_name/?unix_socket=/path/to/socket
     *
     * @param array $elements
     *
     * @return string
     */
    public function buildDatabaseUrl(array $elements): string
    {
        return // scheme
            (isset($elements['scheme']) ? "$elements[scheme]://" : '//') . // host
            (isset($elements['host']) ? ((isset($elements['user']) ?
                    $elements['user'] . (isset($elements['pass']) ? ":$elements[pass]" : '') . '@' : '') .
                $elements['host'] . (isset($elements['port']) ? ":$elements[port]" : '')) : '') . // path
            (isset($elements['path']) ? '/' . $elements['path'] : '') . // memory
            (isset($elements['memory']) ? '/:memory:' : '') . // db_name
            (isset($elements['db_name']) ? '/' . $elements['db_name'] : '') . // query
            (isset($elements['query']) ? '?' .
                (is_array($elements['query']) ? http_build_query($elements['query'], '', '&') : $elements['query']) :
                '');
    }
}