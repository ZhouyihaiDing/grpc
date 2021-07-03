<?php
/*
 *
 * Copyright 2018 gRPC authors.
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
require dirname(__FILE__).'/histogram.php';


/* Start the actual client */
function qps_client_main() {
    echo "[php-client] Initiating php client\n";
    $deadline = Grpc\Timeval::infFuture();
    $req_text = 'client_server_full_request_response';
    $reply_text = 'reply:client_server_full_request_response';
    $status_text = 'status:client_server_full_response_text';

    $server = new Grpc\Server([]);
    $port = $server->addHttp2Port('0.0.0.0:0');
    $server->start();
    $channel = new Grpc\Channel('localhost:'.$port, []);
//             ['credentials' => Grpc\ChannelCredentials::createInsecure()]);

    $warm_up_time = 5;
    $benchmark_time = 20 + $warm_up_time;
    $warm_up_count = 0;
    $benchmark_count = 0;

    $start_time = microtime(true);
    while(1) {
      if(microtime(true) - $start_time > $warm_up_time) {
          break;
      }
      // echo (microtime(true) - $start_time)." ";
      $call = new Grpc\Call($channel, 'dummy_method', $deadline);
      $event = $call->startBatch([
            Grpc\OP_SEND_INITIAL_METADATA => [],
            Grpc\OP_SEND_CLOSE_FROM_CLIENT => true,
            Grpc\OP_SEND_MESSAGE => ['message' => $req_text],
          ]);
      $event = $server->requestCall();
      $server_call = $event->call;
      $event = $server_call->startBatch([
            Grpc\OP_SEND_INITIAL_METADATA => [],
            Grpc\OP_SEND_MESSAGE => ['message' => $reply_text],
            Grpc\OP_SEND_STATUS_FROM_SERVER => [
                'metadata' => [],
                'code' => Grpc\STATUS_OK,
                'details' => $status_text,
            ],
            Grpc\OP_RECV_MESSAGE => true,
            Grpc\OP_RECV_CLOSE_ON_SERVER => true,
          ]);
      $event = $call->startBatch([
            Grpc\OP_RECV_INITIAL_METADATA => true,
            Grpc\OP_RECV_MESSAGE => true,
            Grpc\OP_RECV_STATUS_ON_CLIENT => true,
          ]);
      $status = $event->status;
      $warm_up_count += 1;
    }
    echo "Warm up: finish. count: ".$warm_up_count.PHP_EOL;
    while(1) {
      if(microtime(true) - $start_time > $benchmark_time) {
          break;
      }
      $call = new Grpc\Call($channel, 'dummy_method', $deadline);
      $event = $call->startBatch([
            Grpc\OP_SEND_INITIAL_METADATA => [],
            Grpc\OP_SEND_CLOSE_FROM_CLIENT => true,
            Grpc\OP_SEND_MESSAGE => ['message' => $req_text],
          ]);
      $event = $server->requestCall();
      $server_call = $event->call;
      $event = $server_call->startBatch([
            Grpc\OP_SEND_INITIAL_METADATA => [],
            Grpc\OP_SEND_MESSAGE => ['message' => $reply_text],
            Grpc\OP_SEND_STATUS_FROM_SERVER => [
                'metadata' => [],
                'code' => Grpc\STATUS_OK,
                'details' => $status_text,
            ],
            Grpc\OP_RECV_MESSAGE => true,
            Grpc\OP_RECV_CLOSE_ON_SERVER => true,
          ]);
      $event = $call->startBatch([
            Grpc\OP_RECV_INITIAL_METADATA => true,
            Grpc\OP_RECV_MESSAGE => true,
            Grpc\OP_RECV_STATUS_ON_CLIENT => true,
          ]);
      $status = $event->status;
      $benchmark_count += 1;
    }
    echo "Benchmark: finish. count: ".$benchmark_count.PHP_EOL;
}

qps_client_main();
