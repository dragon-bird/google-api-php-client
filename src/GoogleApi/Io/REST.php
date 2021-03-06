<?php
/*
 * Copyright 2010 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace GoogleApi\Io;

use GoogleApi\Client;
use GoogleApi\Service;
use GoogleApi\External\URITemplateParser;

/**
 * This class implements the RESTful transport of Service\ServiceRequest()'s
 *
 * @author Chris Chabot <chabotc@google.com>
 * @author Chirag Shah <chirags@google.com>
 */
class REST {
  /**
   * Executes a ServiceRequest using a RESTful call by transforming it into
   * an HttpRequest, and executed via IO::authenticatedRequest().
   *
   * @param HttpRequest $req
   * @return array decoded result
   * @throws Service\Exception on server side error (ie: not authenticated,
   *  invalid or malformed post body, invalid url)
   */
  static public function execute(HttpRequest $req) {
    $httpRequest = Client::$io->makeRequest($req);
    $decodedResponse = self::decodeHttpResponse($httpRequest);

    //FIXME currently everything is wrapped in a data envelope, but hopefully this might change some day
    $ret = isset($decodedResponse['data']) ? $decodedResponse['data'] : $decodedResponse;
    return $ret;
  }

  
  /**
   * Decode an HTTP Response.
   * @static
   * @throws Service\Exception
   * @param HttpRequest $response The http response to be decoded.
   * @return mixed|null
   */
  public static function decodeHttpResponse($response) {
    $code = $response->getResponseHttpCode();
    $body = $response->getResponseBody();
    $decoded = null;
    
    if ($code != '200' && $code != '201' && $code != '204') {
      $decoded = json_decode($body, true);
      $err = 'Error calling ' . $response->getRequestMethod() . ' ' . $response->getUrl();
      if ($decoded != null && isset($decoded['error']['message'])  && isset($decoded['error']['code'])) {
        // if we're getting a json encoded error definition, use that instead of the raw response
        // body for improved readability
        $err .= ": ({$decoded['error']['code']}) {$decoded['error']['message']}";
      } else {
        $err .= ": ($code) $body";
      }
      throw new Service\Exception($err, $code);
    }
    
    // Only attempt to decode the response, if the response code wasn't (204) 'no content'
    if ($code != '204') {
      $decoded = json_decode($body, true);
      if ($decoded == null) {
        throw new Service\Exception("Invalid json in service response: $body");
      }
    }
    return $decoded;
  }

  /**
   * Parse/expand request parameters and create a fully qualified
   * request uri.
   * @static
   * @param string $basePath
   * @param string $restPath
   * @param array $params
   * @return string $requestUrl
   */
  static function createRequestUri($basePath, $restPath, $params) {
    $requestUrl = $basePath . $restPath;
    $uriTemplateVars = array();
    $queryVars = array();
    foreach ($params as $paramName => $paramSpec) {
      // Discovery v1.0 puts the canonical location under the 'location' field.
      if (! isset($paramSpec['location'])) {
        $paramSpec['location'] = $paramSpec['restParameterType'];
      }

      if ($paramSpec['type'] == 'boolean') {
        $paramSpec['value'] = ($paramSpec['value']) ? 'true' : 'false';
      }
      if ($paramSpec['location'] == 'path') {
        $uriTemplateVars[$paramName] = $paramSpec['value'];
      } else {
        if (isset($paramSpec['repeated']) && is_array($paramSpec['value'])) {
          foreach ($paramSpec['value'] as $value) {
            $queryVars[] = $paramName . '=' . rawurlencode($value);
          }
        } else {
          $queryVars[] = $paramName . '=' . rawurlencode($paramSpec['value']);
        }
      }
    }

    if (count($uriTemplateVars)) {
      $uriTemplateParser = new URITemplateParser($requestUrl);
      $requestUrl = $uriTemplateParser->expand($uriTemplateVars);
    }
    //FIXME work around for the the uri template lib which url encodes
    // the @'s & confuses our servers.
    $requestUrl = str_replace('%40', '@', $requestUrl);

    if (count($queryVars)) {
      $requestUrl .= '?' . implode($queryVars, '&');
    }

    return $requestUrl;
  }
}
