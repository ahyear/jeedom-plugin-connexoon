<?php
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class benjaminprevotConnexoon extends eqLogic
{

  const ALLOWED_COMMANDS = array(
    'roller_shutter' => array('open', 'close', 'identify', 'stop', 'refresh', 'position', 'position_set', 'position_low_speed' )
  );

  const ALLOWED_PARAMETERS = array('position' => 'int');

  public static function sync()
  {
    ConnexoonLogger::debug('[benjaminprevotConnexoon] Synchronize devices');

    $sites = Somfy::getSites();

    foreach ($sites as $site)
    {
      if (isset($site['id']))
      {
        $devices = Somfy::getDevices($site['id']);

        $capabilityNameFunc = function($capability)
        {
          $name = $capability['name'];

          if ($name == 'position')
          {
            $name = 'position_set';
          }

          return $name;
        };

        foreach ($devices as $device)
        {
          if (in_array('roller_shutter', $device['categories']))
          {
            $logicalId = $device['id'];

            ConnexoonLogger::debug('[benjaminprevotConnexoon] Synchronize ' . $logicalId);

            $benjaminprevotConnexoon = self::byLogicalId($logicalId, Connexoon::ID);

            if (!is_object($benjaminprevotConnexoon))
            {
              $benjaminprevotConnexoon = new benjaminprevotConnexoon();
              $benjaminprevotConnexoon->setName($device['name']);
              $benjaminprevotConnexoon->setEqType_name(Connexoon::ID);
              $benjaminprevotConnexoon->setIsVisible(1);
            }

            $capabilities = $device['capabilities'];
            $actions = array_merge(array_map($capabilityNameFunc, $capabilities), array('position', 'refresh'));

            ConnexoonLogger::debug('[benjaminprevotConnexoon] Actions for ' . $logicalId . ': ' . implode('|', $actions));

            $benjaminprevotConnexoon->setConfiguration('name_somfy', $device['name']);
            $benjaminprevotConnexoon->setConfiguration('type', 'roller_shutter');
            $benjaminprevotConnexoon->setConfiguration('actions', implode('|', $actions));
            $benjaminprevotConnexoon->setLogicalId($logicalId);
            $benjaminprevotConnexoon->setIsEnable($device['available'] == 'true' ? 1 : 0);
            $benjaminprevotConnexoon->save();
            $benjaminprevotConnexoon->refresh();
          }
        }
      }
    }
  }

  public static function cron5()
  {
    self::sync();
  }

  public static function cron30()
  {
    Somfy::refreshToken();
  }

  private function addCommand($logicalId, $name, $genericType, $type = 'action', $subType = 'other', $unite = null, $display = array())
  {
    $actions = explode('|', $this->getConfiguration('actions', ''));
    $allowedActions = self::ALLOWED_COMMANDS[$this->getConfiguration('type', '')];

    if (in_array($logicalId, $actions) && in_array($logicalId, $allowedActions))
    {
      $cmd = $this->getCmd(null, $logicalId);
    
      if (!is_object($cmd))
      {
        $cmd = new benjaminprevotConnexoonCmd();
        $cmd->setLogicalId($logicalId);
        $cmd->setName(__($name, __FILE__));
      }

      $cmd->setGeneric_type($genericType);
      $cmd->setType($type);
      $cmd->setSubType($subType);
      $cmd->setUnite($unite);
      $cmd->setEqLogic_id($this->getId());

      foreach ($display as $key => $value)
      {
        if ($cmd->getDisplay($key, '') == '')
        {
          $cmd->setDisplay($key, $value);
        }
      }

      $cmd->save();
    }
  }

  public function action($action, $parameters = array()) {
    $action = ($action == 'position_set' ? 'position' : $action);

    foreach ($parameters as $key => $value) {
      if (array_key_exists($key, self::ALLOWED_PARAMETERS))
      {
        switch(self::ALLOWED_PARAMETERS[$key])
        {
          case 'int':
            $parameters[$key] = intval($value);
            break;
        }
      }
      else
      {
        unset($parameters[$key]);
      }
    }

    return Somfy::action($this->getLogicalId(), $action, $parameters);
  }

  public function refresh()
  {
    $device = Somfy::getDevice($this->getLogicalId());

    foreach ($device['states'] as $state)
    {
      if ($state['name'] == 'position')
      {
        $this->checkAndUpdateCmd('position', max(0, $state['value']));

        break;
      }
    }
  }

  public function postSave()
  {
    // Action
    $this->addCommand('open', 'Ouvrir', 'FLAP_UP', 'action', 'other', null, array( 'icon' => '<i class="fa fa-chevron-up"></i>' ));
    $this->addCommand('close', 'Fermer', 'FLAP_DOWN', 'action', 'other', null, array( 'icon' => '<i class="fa fa-chevron-down"></i>' ));
    $this->addCommand('identify', 'Identifier', 'ROLLER_IDENTIFY');
    $this->addCommand('stop', 'Stop', 'FLAP_STOP', 'action', 'other', null, array( 'icon' => '<i class="fa fa-stop"></i>' ));
    $this->addCommand('refresh', 'Rafraîchir', 'ROLLER_REFRESH');
    $this->addCommand('position_set', 'Positionner', 'FLAP_SLIDER', 'action', 'slider', '%');
    $this->addCommand('position_low_speed', 'Positionner (lent)', 'FLAP_SLIDER', 'action', 'slider', '%');

    // Info
    $this->addCommand('position', 'Position', 'FLAP_STATE', 'info', 'numeric', '%');
  }

  public function toHtml($_version = 'dashboard')
  {
    $replace = $this->preToHtml($_version);
    if (!is_array($replace))
    {
      return $replace;
    }

    $version = jeedom::versionAlias($_version);
    if ($this->getDisplay('hideOn' . $version) == 1)
    {
      return '';
    }

    foreach (self::ALLOWED_COMMANDS[$this->getConfiguration('type', '')] as $cmd)
    {
      $replace['#action_' . $cmd . '_hide#'] = 'display:none;';
    }

    foreach ($this->getCmd('info') as $cmd)
    {
      $replace['#info_' . $cmd->getLogicalId() . '_value#'] = $cmd->execCmd();
      $replace['#info_' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
    }

    foreach ($this->getCmd('action') as $cmd)
    {
      $replace['#action_' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
      $replace['#action_' . $cmd->getLogicalId() . '_hide#'] = $cmd->getIsVisible() ? '' : 'display:none;';
    }
    
    return template_replace($replace, getTemplate('core', $version, 'eqLogic', Connexoon::ID));
  }

}

class benjaminprevotConnexoonCmd extends cmd
{

  public function execute($options = array())
  {
    if ($this->getType() == '')
    {
        return;
    }

    $eqLogic = $this->getEqLogic();
    $action = $this->getLogicalId();

    if ($this->getType() == 'action')
    {
      if ($action == 'refresh')
      {
        $eqLogic->refresh();
        $eqLogic->refreshWidget();
      }
      else if ($action == 'position_set' || $action == 'position_low_speed')
      {
        $eqLogic->action($action, array('position' => $options['slider']));
      }
      else
      {
        $eqLogic->action($action);
      }
    }
  }

}

/**
 * Connexoon
 */
class Connexoon
{
  const ID = 'benjaminprevotConnexoon';
}

/**
 * ConnexoonLogger
 */

class ConnexoonLogger
{
  private static function log($level, $message)
  {
    log::add(Connexoon::ID, $level, $message);
  }

  public static function debug($message)
  {
    self::log('debug', $message);
  }

  public static function info($message)
  {
    self::log('info', $message);
  }

  public static function warning($message)
  {
    self::log('warning', $message);
  }

  public static function error($message)
  {
    self::log('error', $message);
  }
}

/**
 * ConnexoonConfiguration
 */
class ConnexoonConfig
{
  const ACCESS_TOKEN_KEY = 'access_token';

  const CONSUMER_KEY = 'consumer_key';

  const CONSUMER_STATE = 'consumer_state';

  const REFRESH_TOKEN = 'refresh_token';
  
  const TOKEN_EXISTS = 'token_exists';

  public static function get($key) {
    return config::byKey($key, Connexoon::ID);
  }

  public static function set($key, $value) {
    config::save($key, $value, Connexoon::ID);
  }

  public static function unset($key) {
    config::remove($key, Connexoon::ID);
  }

  public static function getAccessToken()
  {
    return self::get(self::ACCESS_TOKEN_KEY);
  }

  public static function setAccessToken($accessToken)
  {
    return self::set(self::ACCESS_TOKEN_KEY, $accessToken);
  }

  public static function unsetAccessToken()
  {
    return self::unset(self::ACCESS_TOKEN_KEY);
  }

  public static function unsetRefreshToken()
  {
    return self::unset(self::REFRESH_TOKEN);
  }

  public static function unsetTokenExists()
  {
    return self::unset(self::TOKEN_EXISTS);
  }

  public static function setConsumerState($consumerState)
  {
    return self::set(self::CONSUMER_STATE, $consumerState);
  }

  public static function getConsumerKey()
  {
    return self::get(self::CONSUMER_KEY);
  }
}

/**
 * HTTP
 */
class ConnexoonHttpResponse
{
  private $_code;

  private $_content;

  public function __construct($code, $content)
  {
    $this->_code = $code;
    $this->_content = $content;
  }

  public function getCode()
  {
    return $this->_code;
  }

  public function getContent()
  {
    return $this->_content;
  }

  public function getContentAsJsonArray()
  {
    return json_decode($this->_content, true);
  }
}

class ConnexoonHttpRequest
{
  const METHOD_GET = 'GET';

  const METHOD_POST = 'POST';

  public static function request($method, $url)
  {
    $request = new ConnexoonHttpRequest($method);
    $request->_url = $url;

    return $request;
  }

  public static function get($url)
  {
    return self::request(self::METHOD_GET, $url);
  }

  public static function post($url)
  {
    return self::request(self::METHOD_POST, $url);
  }

  private $_method;

  private $_url;

  private $_params;

  private $_headers;

  private $_content;

  private function __construct($method)
  {
    $this->_method = $method;
    $this->_params = array();
    $this->_headers = array();
    $this->_content = '';
  }

  private function header_map($key, $value)
  {
    return $key . ': ' . $value;
  }

  public function param($key, $value)
  {
    $this->_params[$key] = $value;

    return $this;
  }

  public function header($key, $value)
  {
    $this->_headers[$key] = $value;

    return $this;
  }

  public function content($content)
  {
    $this->_content = $content;

    return $this;
  }

  public function buildUrl()
  {
    return $this->_url . (count($this->_params) == 0 ? '' : '?') . http_build_query($this->_params);
  }

  public function send()
  {
    // Build url with query parameters if exist
    $url = $this->buildUrl();

    // Build headers array
    $headers = array_map(array($this, 'header_map'), array_keys($this->_headers), $this->_headers);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if ($this->_method === self::METHOD_POST) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $this->_content);
    }
    
    $content = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    ConnexoonLogger::debug('[HTTP] ' . $this->_method . ' - ' . $url . ' - Status: ' . $code . ' - Body: ' . $this->_content);

    curl_close($ch);

    return new ConnexoonHttpResponse($code, $content);
  }
}

/**
 * Somfy
 */
class Somfy
{
  private static function saveToken($response)
  {
    $code = $response->getCode();

    if ($code == 200)
    {
      $json = $response->getContentAsJsonArray();

      if (isset($json['access_token']) && isset($json['refresh_token']))
      {
        ConnexoonConfig::setAccessToken($json['access_token']);
        ConnexoonConfig::set('refresh_token', $json['refresh_token']);
        ConnexoonConfig::set('token_exists', 'true');

        ConnexoonLogger::debug('[Somfy] Token saved');
      }
      else
      {
        ConnexoonConfig::set('token_exists', 'false');

        ConnexoonLogger::error('[Somfy] Incorrect token format: ' . print_r($json, true));
      }
    }
    else
    {
      ConnexoonLogger::error("[Somfy] An error occured while refreshing token - HTTP code: $code");
    }
  }

  public static function getToken($code, $state)
  {
    ConnexoonLogger::debug('[Somfy] Get token');

    $response = ConnexoonHttpRequest::get('https://accounts.somfy.com/oauth/oauth/v2/token')
        ->param('client_id', ConnexoonConfig::get('consumer_key'))
        ->param('client_secret', ConnexoonConfig::get('consumer_secret'))
        ->param('grant_type', 'authorization_code')
        ->param('code', $code)
        ->param('redirect_uri', ConnexoonConfig::get('callback_url'))
        ->param('state', $state)
        ->send();

    self::saveToken($response);
  }

  public static function refreshToken()
  {
    ConnexoonLogger::debug('[Somfy] Refresh token');

    $response = ConnexoonHttpRequest::get('https://accounts.somfy.com/oauth/oauth/v2/token')
        ->param('client_id', ConnexoonConfig::getConsumerKey())
        ->param('client_secret', ConnexoonConfig::get('consumer_secret'))
        ->param('refresh_token', ConnexoonConfig::get('refresh_token'))
        ->param('grant_type', 'refresh_token')
        ->send();
    
    self::saveToken($response);
  }

  public static function getAuthUrl()
  {
    $state = hash("sha256", rand());
    ConnexoonConfig::setConsumerState($state);

    return ConnexoonHttpRequest::get('https://accounts.somfy.com/oauth/oauth/v2/auth')
        ->param('response_type', 'code')
        ->param('client_id', ConnexoonConfig::getConsumerKey())
        ->param('redirect_uri', ConnexoonConfig::get('callback_url'))
        ->param('state', $state)
        ->param('grant_type', 'authorization_code')
        ->buildUrl();
  }

  private static function api($url, $method = ConnexoonHttpRequest::METHOD_GET, $content = '', $limit = 5)
  {
    ConnexoonLogger::debug("[Somfy] Call $url - try $limit");

    if ($limit < 1) {
      ConnexoonLogger::error("[Somfy] $method - $url -  Number of tries exceeded");

      return false;
    }

    $response = ConnexoonHttpRequest::request($method, $url)
        ->header('Authorization', 'Bearer ' . ConnexoonConfig::get('access_token'))
        ->header('Content-Type', 'application/json')
        ->content($content)
        ->send();
    
    $code = $response->getCode();
    $json = $response->getContentAsJsonArray();

    if ($code > 299)
    {
      ConnexoonLogger::warning("[Somfy] Code received $code - retry");

      return self::api($url, $method, $content, $limit - 1);
    }
    elseif (isset($json['fault'])
      && isset($json['fault']['detail'])
      && isset($json['fault']['detail']['errorcode'])
      && $json['fault']['detail']['errorcode'] == 'keymanagement.service.access_token_expired')
    {
      ConnexoonLogger::info('[Somfy] Refresh token and retry');

      self::refreshToken();

      return self::api($url, $method, $content, $limit - 1);
    }

    return $json;
  }

  public static function getSites()
  {
    ConnexoonLogger::debug('[Somfy] Get sites list');

    return self::api('https://api.somfy.com/api/v1/site');
  }

  public static function getDevices($siteId)
  {
    ConnexoonLogger::debug("[Somfy] Get devices list for site $siteId");

    return self::api("https://api.somfy.com/api/v1/site/$siteId/device");
  }

  public static function getDevice($deviceId)
  {
    ConnexoonLogger::debug("[Somfy] Get device information for $deviceId");

    return self::api("https://api.somfy.com/api/v1/device/$deviceId");
  }

  public static function action($deviceId, $action, $parameters = array())
  {
    ConnexoonLogger::debug("[Somfy] Launch action $action for device $deviceId");

    $mapParameter = function($key, $value) {
      return array('name' => $key, 'value' => $value);
    };

    return self::api("https://api.somfy.com/api/v1/device/$deviceId/exec", ConnexoonHttpRequest::METHOD_POST, '{ "name": "' . $action . '", "parameters": ' . json_encode(array_map($mapParameter, array_keys($parameters), $parameters)) . ' }');
  }
}
