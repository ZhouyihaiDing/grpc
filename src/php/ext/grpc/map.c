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

#include "map.h"

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <php.h>
#include <php_ini.h>
#include <ext/standard/info.h>
#include <ext/spl/spl_exceptions.h>
#include "php_grpc.h"

#include <zend_exceptions.h>

#include <stdbool.h>

#include <grpc/grpc.h>
#include <grpc/support/time.h>
#include <grpc/support/port_platform.h>

#include <string.h>

#include <grpc/support/alloc.h>
#include <grpc/support/log.h>

void grpc_time_channel_key_map_init(grpc_time_channel_key_map* map,
                                 size_t initial_capacity) {
  GPR_ASSERT(initial_capacity > 1);
  map->header =
      (channel_persistent_le_t*)(pemalloc(sizeof(channel_persistent_le_t) * 1));
  map->tail =
      (channel_persistent_le_t*)(pemalloc(sizeof(channel_persistent_le_t) * 1));
  map->count = 0;
  map->capacity = initial_capacity;
  map->capacity_remain = 0;
}

void grpc_time_channel_key_map_destroy(grpc_time_channel_key_map* map) {
  // TODO: free all capacity_remain first
  gpr_free(map->header);
  gpr_free(map->tail);
}

void grpc_time_key_map_update(grpc_time_channel_key_map* map,
                        channel_persistent_le_t le) {
  le->prev->next = le->next;
  le->next->prev = le->prev;

  le->prev = tail->prev;
  le->prev->next = le;

  le->next = map->tail;
  map->prev = le;
}

void grpc_time_channel_key_map_add(grpc_time_channel_key_map* map,
                        channel_persistent_le_t le) {

  le->prev = tail->prev;
  le->prev->next = le;

  le->next = map->tail;
  map->prev = le;

  map->count += 1;
}

void* grpc_time_channel_key_map_delete(grpc_time_channel_key_map* map,
                        channel_persistent_le_t le) {
  le->prev->next = le->next;
  le->next->prev = le->prev;

  le->next = map->tail->next;
  le->next->prev = le;

  map->tail->next = le;
  le->prev = map->tail;

  map->capacity_remain += 1;
}


void* grpc_time_channel_key_map_get_free(grpc_time_channel_key_map* map,
                        channel_persistent_le_t le) {
  if(capacity_remain == 0) {
    return NULL;
  }
  grpc_time_channel_key_map* ret_val = tail->next;
  tail->next = ret_val->next;
  return ret_val;
}

size_t grpc_time_channel_key_map_capacity_remain(grpc_time_channel_key_map* map) {
  return map->capacity_remain;
}


size_t grpc_time_channel_key_map_size(grpc_time_channel_key_map* map) {
  return map->count;
}


void grpc_time_channel_key_map_for_each(grpc_time_channel_key_map* map,
                                     void (*f)(void* user_data, double key,
                                               void* value),
                                     void* user_data) {
  size_t i;

  for (i = 0; i < map->count; i++) {
    if (map->values[i]) {
      f(user_data, map->keys[i], map->values[i]);
    }
  }
}
