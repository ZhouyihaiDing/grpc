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

class PersistentListTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {
    }

    public function tearDown()
    {

        $channel_destory_persistent =
          new Grpc\Channel('localhost:01010', []);
//        $channel_destory_persistent->destoryPersistentList();
//        $channel_destory_persistent->printPersistentList();
//        $channel_destory_persistent->initPersistentList();
    }


    public function testPersistentChannelSameHost()
    {
        $this->channel1 = new Grpc\Channel('localhost:1', []);
        // the underlying grpc channel is the same by default
        // when connecting to the same host
        $this->channel2 = new Grpc\Channel('localhost:1', []);

        //ref_count should be 2
        $this->channel1->printPersistentList();

        $this->channel1->close();
        //ref_count should be 1
        $this->channel1->printPersistentList();

        $this->channel2->close();
        //ref_count should be 0
        $this->channel1->printPersistentList();
    }

    public function testPersistentChannelDifferentHost()
    {
        // two different underlying channels because different hostname
        $this->channel1 = new Grpc\Channel('localhost:1', []);
        $this->channel2 = new Grpc\Channel('localhost:2', []);

        $this->channel1->printPersistentList();

        $this->channel1->close();
        $this->channel2->close();
    }


    public function testPersistentChannelTimeout()
    {
      // the default timeout is 30ms.
      $this->channel1 = new Grpc\Channel('localhost:1', []);
      $this->channel1->printPersistentList();
      usleep(15*1000);
      $this->channel2 = new Grpc\Channel('localhost:2', []);
      $this->channel1->printPersistentList();
      usleep(31*1000);
      $this->channel3 = new Grpc\Channel('localhost:3', []);
      echo "channel1 and channel2 should be expiredddddddddddddddddd-------------\n";
      $this->channel1->printPersistentList();

      $this->channel1->close();
      $this->channel2->close();
      $this->channel3->close();
    }

    public function testPersistentChannelOutbound()
    {
      // the default size is 3
      $this->channel1 = new Grpc\Channel('localhost:1', []);
      $this->channel1->printPersistentList();
      $this->channel2 = new Grpc\Channel('localhost:2', []);
      $this->channel1->printPersistentList();
      $this->channel3 = new Grpc\Channel('localhost:3', []);
      $this->channel1->printPersistentList();
      $this->channel4 = new Grpc\Channel('localhost:4', []);
      $this->channel1->printPersistentList();

      $this->channel1->close();
      $this->channel2->close();
      $this->channel3->close();
      $this->channel4->close();
      //$this->channel1->printPersistentList();
    }

}
