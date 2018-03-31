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

void php_grpc_time_key_map_init(php_grpc_time_key_map* map,
                                 size_t initial_capacity) {
  php_printf("php_grpc_time_key_map_init start\n");
  GPR_ASSERT(initial_capacity > 1);
  map->header =
      (channel_persistent_le_t*)(pemalloc(sizeof(channel_persistent_le_t) * 1, true));
  map->tail =
      (channel_persistent_le_t*)(pemalloc(sizeof(channel_persistent_le_t) * 1, true));
  map->header->next = map->tail;
  map->tail->prev = map->header;
  map->header->prev = NULL;
  map->tail->next = NULL;
  map->count = (size_t*)(pemalloc(sizeof(size_t) * 1, true));
  *map->count = 0;
//  php_printf("php_grpc_time_key_map_init end\n");
}

void php_grpc_time_key_map_destroy(php_grpc_time_key_map* map) {
  php_printf("php_grpc_time_key_map_destroy start\n");
  channel_persistent_le_t* cur = map->header;
  channel_persistent_le_t* tmp = NULL;
  while(cur != map->tail && cur != NULL){
    tmp = cur->next;
    pefree(cur, true);
    cur = tmp;
  }
  pefree(map->header, true);
  pefree(map->tail, true);
//  php_printf("php_grpc_time_key_map_destroy end\n");
}

void php_grpc_time_key_map_update(php_grpc_time_key_map* map,
                        channel_persistent_le_t* le) {
  php_printf("grpc_time_key_map_update start\n");
  le->prev->next = le->next;
  le->next->prev = le->prev;

  le->prev = map->tail->prev;
  le->prev->next = le;

  le->next = map->tail;
  map->tail->prev = le;
//  php_printf("grpc_time_key_map_update end\n");
}

void php_grpc_time_key_map_append(php_grpc_time_key_map* map,
                        channel_persistent_le_t* le) {
  php_printf("php_grpc_time_key_map_add start\n");

  le->prev = map->tail->prev;
  le->prev->next = le;

  le->next = map->tail;
  map->tail->prev = le;

  *map->count += 1;
//  php_printf("php_grpc_time_key_map_add end\n");
}

void* php_grpc_time_key_map_delete(php_grpc_time_key_map* map,
                        channel_persistent_le_t* le) {
  php_printf("php_grpc_time_key_map_delete start\n");
  le->prev->next = le->next;
  le->next->prev = le->prev;

  le->next = map->tail->next;
  if(le->next != NULL) {
    le->next->prev = le;
  }

  map->tail->next = le;
  le->prev = map->tail;

  *le->ref_count = 0;

  *map->count -= 1;
//  php_printf("php_grpc_time_key_map_delete end\n");
  return le;
}


void* php_grpc_time_key_map_get_free(php_grpc_time_key_map* map,
                        channel_persistent_le_t* le) {
  php_printf("php_grpc_time_key_map_get_free start\n");
  channel_persistent_le_t* ret_val = map->tail->next;
  map->tail->next = ret_val->next;
  php_printf("php_grpc_time_key_map_get_free end\n");
  return ret_val;
}


size_t php_grpc_time_key_map_size(php_grpc_time_key_map* map) {
  php_printf("php_grpc_time_key_map_size: %zu\n", *map->count);
  return *map->count;
}

void* grpc_time_key_map_get_top(php_grpc_time_key_map* map) {
  //return NULL;
  if(map->header->next == NULL){
     php_printf("grpc_time_key_map_get_top error\n");
  }
  return map->header->next;
}

void* grpc_time_key_map_get_first_free(php_grpc_time_key_map* map) {
  channel_persistent_le_t* cur = map->header->next;
//  while(cur != map->tail){
//    if(*cur->ref_count == 0) {
//      break;
//    }
//    cur = cur->next;
//  }
  php_printf("grpc_time_key_map_get_first_free channel count: %zu\n", *map->count);
  size_t i;
  for (i = 0; i < *map->count; i++) {
    if(*cur->ref_count == 0) {
      break;
    }
    cur = cur->next;
  }
  if(cur == map->tail){
    php_printf("return pos 1\n");
    return NULL;
  }
  php_printf("return pos 2\n");
  return cur;
}

void grpc_time_print_timespec(gpr_timespec* time_spec){
  php_printf("time spec: second -- %" PRId64 "  nsecond %" PRId32 "\n",
      time_spec->tv_sec , time_spec->tv_nsec);
}

void php_grpc_time_key_map_print(php_grpc_time_key_map* map) {
  size_t i;
  php_printf("print persistent channel count: %zu\n", *map->count);
  channel_persistent_le_t* cur = map->header->next;
  for (i = 0; i < *map->count; i++) {
    php_printf("channel: %zu, target: %s, key: %s, ref_count: %zu\n", i, cur->channel->target, cur->channel->key,
    *cur->ref_count);
    grpc_time_print_timespec(cur->time);
    cur = cur->next;
  }
  // cur is in tail.
  if(cur == NULL) php_printf("wrongggggggggggggggggggggggg\n");
  //if(cur == map->tail) php_printf("righttttttttttttttttttttttttttttt\n");
  cur = cur->next;
  while(cur != NULL){
    php_printf("=======\n");
    if(cur->channel != NULL){
      php_printf("channel: %zu, target: %s, key: %s, ref_count: %zu\n", i, cur->channel->target, cur->channel->key,
        *cur->ref_count);
      grpc_time_print_timespec(cur->time);
    } else {
      php_printf("channel = NULL xxxxx wrongggggg\n");
    }
    cur = cur->next;
    i++;
  }
}

void php_grpc_time_key_map_re_init_test(php_grpc_time_key_map* map){
  *map->count = 0;
  map->header->next = map->tail;
  map->tail->prev = map->header;
  map->tail->next = NULL;
}
