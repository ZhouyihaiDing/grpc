<?php

namespace Grpc_gcp;

//require_once(dirname(__FILE__).'/generated/Grpc_gcp/ExtensionConfig.php');
//require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig.php');
//require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig_Command.php');
//require_once(dirname(__FILE__).'/generated/Grpc_gcp/ApiConfig.php');
//require_once(dirname(__FILE__).'/generated/Grpc_gcp/ChannelPoolConfig.php');
//require_once(dirname(__FILE__).'/generated/Grpc_gcp/MethodConfig.php');
//require_once(dirname(__FILE__).'/generated/GPBMetadata/GrpcGcp.php');

use Google\Auth\ApplicationDefaultCredentials;

function parseConfObject($conf_object) {
  $config = json_decode($conf_object->serializeToJsonString(), true);
  $api_conf = $config['api'][0];
  $global_conf['target'] = $api_conf['target'];
  $global_conf['channelPool'] = $api_conf['channelPool'];
  $aff_by_method = array();
  for ($i = 0; $i < count($api_conf['method']); $i++) {
    // In proto3, if the value is default, eg 0 for int, it won't be serialized.
    // Thus serialized string may not have `command` if the value is default 0(BOUND).
    if (!array_key_exists('command', $api_conf['method'][$i]['affinity'])) {
      $api_conf['method'][$i]['affinity']['command'] = 'BOUND';
    }
    $aff_by_method[$api_conf['method'][$i]['name'][0]] = $api_conf['method'][$i]['affinity'];
  }
  $global_conf['affinity_by_method'] = $aff_by_method;
  return $global_conf;
}

function enableGrpcGcp($conf) {
  $channel_pool_key = 'gcp_channel'.getmypid();
  $gcp_call_invoker = new GCPCallInvoker();
  if(apcu_exists($channel_pool_key)) {
    echo "hasssssssssssssssssskey\n";
    $gcp_channel = apcu_fetch($channel_pool_key);
    register_shutdown_function(function ($channel, $channel_pool_key) {
      // Push the current gcp_channel back into the pool when the script finishes.
      //  $global_conf['gcp_channel'.getmypid()] = $channel;
      echo "register_shutdown_function ". $channel->version. "\n";
      apcu_delete($channel_pool_key);
      apcu_add($channel_pool_key, $channel);
    }, $gcp_channel, $channel_pool_key);
    $gcp_channel->reCreateCredentials();
    return [$gcp_channel, $gcp_call_invoker];
  } else {
    echo "not has key!!!!!!!!!!!!!!!\n";
    $global_conf = parseConfObject($conf);
    // Create GCP channel based on the information.
    $hostname = $global_conf['target'][0];
    $credentials = \Grpc\ChannelCredentials::createSsl();
    $auth = ApplicationDefaultCredentials::getCredentials();
    $opts = [
      'credentials' => $credentials,
      'update_metadata' => $auth->getUpdateMetadataFunc(),
      'global_conf' => $global_conf,
    ];
    $gcp_channel = new \Grpc_gcp\GrpcExtensionChannel($hostname, $opts);
    apcu_add('gcp_channel' . getmypid(), $gcp_channel);
    return [$gcp_channel, $gcp_call_invoker];
  }
}
