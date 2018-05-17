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

#ifndef NET_GRPC_PHP_GRPC_CHANNEL_EXTENSION_H_
#define NET_GRPC_PHP_GRPC_CHANNEL_EXTENSION_H_

#include "php_grpc.h"
#include "channel.h"

/* Class entry for the PHP Channel class */
extern zend_class_entry *grpc_ce_channel;

typedef struct _grpc_extension_channel {
  wrapped_grpc_channel **wrapped_pool;
  HashTable *key;
  HashTable *target;
  int _max_size;
  int _max_concurrent_streams_low_watermark;
  char* _target;
  HashTable _options;
  char* _credentials;
  // A map of {method name: affinity config}
  HashTable _affinity_by_method;
  gpr_mu _lock;
  // A map of {affinity key: channel_ref_data}.
  HashTable_channel_ref_by_affinity_key;
  // A map of managed channel refs.
  HashTable _channel_refs;
  HashTable _subscribers;
} grpc_extension_channel;

void _get_channel_ref(){}

void _bind(){}

void _bound(){}

void _unbind(){}

void pre_process(){}

void post_process(){}

#endif /* NET_GRPC_PHP_GRPC_CHANNEL_H_ */
