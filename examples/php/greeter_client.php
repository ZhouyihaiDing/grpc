<?php
/*
 *
 * Copyright 2015 gRPC authors.
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
 *
 */

require dirname(__FILE__).'/vendor/autoload.php';

// The following includes are needed when using protobuf 3.1.0
// and will suppress warnings when using protobuf 3.2.0+
require dirname(__FILE__).'/Helloworld/GreeterClient.php';
require dirname(__FILE__).'/Helloworld/HelloRequest.php';
require dirname(__FILE__).'/Helloworld/HelloReply.php';
require dirname(__FILE__).'/GPBMetadata/Helloworld.php';

$change_metadata = function($method, &$argument, &$deserialize, &$metadata, &$options) {
  $metadata["foo"] = array('interceptor_from_request_response');
};

$change_request = function($method, &$argument, &$deserialize, &$metadata, &$options) {
  echo "excute changing request\n";
  $argument->setName("world from interceptor");
  if($argument->getName() == "REST"){
    return true;
  }
};

$log_method = function ($method, &$argument, &$deserialize, &$metadata, &$options) {
  ini_set("log_errors", 1);
  ini_set("error_log", "php-error.log");
  error_log("Sending request/response to");
  error_log($method);
  $metadata['request_id'] = array((string) rand());
};


$intercept_change_metadata = new Grpc\InterceptorChannel();
$intercept_change_metadata->setUnaryUnaryIntercept($change_metadata);
$intercept_change_request = new Grpc\InterceptorChannel();
$intercept_change_request->setUnaryUnaryIntercept($change_request);
$intercept_add_log = new Grpc\InterceptorChannel();
$intercept_add_log->setUnaryUnaryIntercept($log_method);

$name = !empty($argv[1]) ? $argv[1] : 'world';

// 1. Passing a real channel
$real_channel = new Grpc\Channel('localhost:50051', [
  'credentials' => Grpc\ChannelCredentials::createInsecure(),
]);
$intercept_real_channel = new Grpc\InterceptorChannel($real_channel);
// 2. Passing a interceptor channel without real channel at the tail
$intercept_channel_without_real_channel = new Grpc\InterceptorChannel();
// 3. has real channel at the tail
$intercept_channel_with_real_channel = new Grpc\InterceptorChannel();
$intercept_channel_with_real_channel->insertInterceptorChannel($intercept_real_channel);

$client = new Helloworld\GreeterClient('localhost:50051', [
  'credentials' => Grpc\ChannelCredentials::createInsecure(),
],
  $intercept_change_request
);

$request = new Helloworld\HelloRequest();
$request->setName($name);
$call = $client->SayHello($request);
if($call) {
  echo "the call is created\n";
  list($reply, $status) = $call->wait();
  $message = $reply->getMessage();
  echo $message."\n";
}


