<?php
namespace Phppot;

class TwitterOauthService
{

    private $consumerKey;

    private $consumerSecret;

    private $signatureMethod = 'HMAC-SHA1';

    private $oauthVersion = '1.0';

    private $http_status = "";

    public function __construct()
    {
        require_once __DIR__ . '/../Common/Config.php';
        $this->consumerKey = Config::TW_CONSUMER_KEY;
        $this->consumerSecret = Config::TW_CONSUMER_SECRET;
    }

    public function getOauthVerifier()
    {
        $requestResponse = $this->getRequestToken();
        $authUrl = "https://api.twitter.com/oauth/authenticate";
        $redirectUrl = $authUrl . "?oauth_token=" . $requestResponse["request_token"];

        return $redirectUrl;
    }

    public function getRequestToken()
    {
        $url = "https://api.twitter.com/oauth/request_token";

        $params = array(
            'oauth_callback' => Config::TW_CALLBACK_URL,
            "oauth_consumer_key" => $this->consumerKey,
            "oauth_nonce" => $this->getToken(42),
            "oauth_signature_method" => $this->signatureMethod,
            "oauth_timestamp" => time(),
            "oauth_version" => $this->oauthVersion
        );

        $params['oauth_signature'] = $this->createSignature('POST', $url, $params);


        $oauthHeader = $this->generateOauthHeader($params);

        $response = $this->curlHttp('POST', $url, $oauthHeader);

        $responseVariables = array();
        parse_str($response, $responseVariables);

        $tokenResponse = array();

        $tokenResponse["request_token"] = $responseVariables["oauth_token"];
        $tokenResponse["request_token_secret"] = $responseVariables["oauth_token_secret"];

        session_start();
        $_SESSION["oauth_token"] = $tokenResponse["request_token"];
        $_SESSION["oauth_token_secret"] = $tokenResponse["request_token_secret"];
        session_write_close();

        return $tokenResponse;
    }

    public function getAccessToken($oauthVerifier, $oauthToken, $oauthTokenSecret)
    {
        $url = 'https://api.twitter.com/oauth/access_token';

        $oauthPostData = array(
            'oauth_verifier' => $oauthVerifier
        );

        $params = array(
            "oauth_consumer_key" => $this->consumerKey,
            "oauth_nonce" => $this->getToken(42),
            "oauth_signature_method" => $this->signatureMethod,
            "oauth_timestamp" => time(),
            "oauth_token" => $oauthToken,
            "oauth_version" => $this->oauthVersion
        );

        $params['oauth_signature'] = $this->createSignature('POST', $url, $params, $oauthTokenSecret);

        $oauthHeader = $this->generateOauthHeader($params);

        $response = $this->curlHttp('POST', $url, $oauthHeader, $oauthPostData);
        $fp = fopen("eg.log", "a");
        fwrite($fp, "AccessToken: " . $response . "\n");

        $responseVariables = array();
        parse_str($response, $responseVariables);

        $tokenResponse = array();
        $tokenResponse["access_token"] = $responseVariables["oauth_token"];
        $tokenResponse["access_token_secret"] = $responseVariables["oauth_token_secret"];

        return $tokenResponse;
    }

    public function getUserData($oauthVerifier, $oauthToken, $oauthTokenSecret)
    {
        $accessTokenResponse = $this->getAccessToken($oauthVerifier, $oauthToken, $oauthTokenSecret);

        $url = 'https://api.twitter.com/1.1/account/verify_credentials.json';

        $params = array(
            "oauth_consumer_key" => $this->consumerKey,
            "oauth_nonce" => $this->getToken(42),
            "oauth_signature_method" => $this->signatureMethod,
            "oauth_timestamp" => time(),
            "oauth_token" => $accessTokenResponse["access_token"],
            "oauth_version" => $this->oauthVersion
        );

        $params['oauth_signature'] = $this->createSignature('GET', $url, $params, $accessTokenResponse["access_token_secret"]);

        $oauthHeader = $this->generateOauthHeader($params);

        $response = $this->curlHttp('GET', $url, $oauthHeader);

        return $response;
    }

    public function curlHttp($httpRequestMethod, $url, $oauthHeader, $post_data = null)
    {

        $ch = curl_init();
        $fp = fopen("eg.log", "a");
        fwrite($fp, "Header: " . $oauthHeader . "\n");

        $headers = array(
            "Authorization: OAuth " . $oauthHeader
        );

        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => false,
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ];
        if($httpRequestMethod == 'POST') {
            $options[CURLOPT_POST] = true;
        }
        if(!empty($post_data)) {
            $options[CURLOPT_POSTFIELDS] = $post_data;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        $this->http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response;
    }

    public function generateOauthHeader($params)
    {
        foreach ($params as $k => $v) {

            $oauthParamArray[] = $k . '="' . rawurlencode($v) . '"';
        }
        $oauthHeader = implode(', ', $oauthParamArray);

        return $oauthHeader;
    }

    public function createSignature($httpRequestMethod, $url, $params, $tokenSecret = '')
    {
        $strParams = rawurlencode(http_build_query($params));

        $baseString = $httpRequestMethod . "&" . rawurlencode($url) . "&" . $strParams;

        $fp = fopen("eg.log", "a");
        fwrite($fp, "Baaaase: " . $baseString . "\n");

        $signKey = $this->generateSignatureKey($tokenSecret);
        $oauthSignature = base64_encode(hash_hmac('sha1', $baseString, $signKey, true));

        return $oauthSignature;
    }

    public function generateSignatureKey($tokenSecret)
    {
        $signKey = rawurlencode($this->consumerSecret) . "&";
        if (! empty($tokenSecret)) {
            $signKey = $signKey . rawurlencode($tokenSecret);
        }
        return $signKey;
    }

    public function getToken($length)
    {
        $token = "";
        $codeAlphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $codeAlphabet .= "abcdefghijklmnopqrstuvwxyz";
        $codeAlphabet .= "0123456789";
        $max = strlen($codeAlphabet) - 1;
        for ($i = 0; $i < $length; $i ++) {
            $token .= $codeAlphabet[$this->cryptoRandSecure(0, $max)];
        }
        return $token;
    }

    public function cryptoRandSecure($min, $max)
    {
        $range = $max - $min;
        if ($range < 1) {
            return $min; // not so random...
        }
        $log = ceil(log($range, 2));
        $bytes = (int) ($log / 8) + 1; // length in bytes
        $bits = (int) $log + 1; // length in bits
        $filter = (int) (1 << $bits) - 1; // set all lower bits to 1
        do {
            $rnd = hexdec(bin2hex(openssl_random_pseudo_bytes($bytes)));
            $rnd = $rnd & $filter; // discard irrelevant bits
        } while ($rnd >= $range);
        return $min + $rnd;
    }
}
