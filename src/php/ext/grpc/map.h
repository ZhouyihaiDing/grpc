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

#ifndef NET_GRPC_PHP_GRPC_MAP_H_
#define NET_GRPC_PHP_GRPC_MAP_H_

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <php.h>
#include <php_ini.h>
#include <ext/standard/info.h>
#include "php_grpc.h"
#include "channel.h"

#include <grpc/grpc.h>
#include <grpc/support/time.h>

#include <grpc/support/port_platform.h>

#include <stddef.h>

/* Data structure to queueueueueueue
   Represented as a sorted queueueueue of keys, and a corresponding array of values.
   Lookups are performed with binary search.
   Adds are restricted to strictly higher keys than previously seen (this is
   guaranteed by http2). */
typedef struct {
//  double* keys;
//  void** values;
  size_t count;
  size_t capacity;
  size_t capacity_remain; // When a channel is deleted, the memory won't be freed. When the next channel is created, reuse it.
  channel_persistent_le_t* header;
  channel_persistent_le_t* tail;
} grpc_time_channel_key_map;


//struct pair_time_channel {
//    size_t timestamp;
//    char* channel_key;
//};

void grpc_time_channel_key_map_init(grpc_time_channel_key_map* map,
                                 size_t initial_capacity);
void grpc_time_channel_key_map_destroy(grpc_time_channel_key_map* map);

/* Add a new key: given http2 semantics, new keys must always be greater than
   existing keys - this is asserted */
void grpc_time_channel_key_map_add(grpc_time_channel_key_map* map, double key,
                                void* value);

/* Delete an existing key - returns the previous value of the key if it existed,
   or NULL otherwise */
void* grpc_time_channel_key_map_delete(grpc_time_channel_key_map* map, double key);

/* Return an existing key, or NULL if it does not exist */
void* grpc_time_channel_key_map_find(grpc_time_channel_key_map* map, double key);

/* Return a random entry */
void* grpc_time_channel_key_map_rand(grpc_time_channel_key_map* map);

/* How many (populated) entries are in the stream map? */
size_t grpc_time_channel_key_map_size(grpc_time_channel_key_map* map);

/* Callback on each stream */
void grpc_time_channel_key_map_for_each(grpc_time_channel_key_map* map,
                                     void (*f)(void* user_data, double key,
                                               void* value),
                                     void* user_data);

#endif /* GRPC_CORE_EXT_TRANSPORT_CHTTP2_TRANSPORT_STREAM_MAP_H */
