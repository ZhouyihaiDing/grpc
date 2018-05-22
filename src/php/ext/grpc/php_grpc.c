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

#include "php_grpc.h"

#include "call.h"
#include "channel.h"
#include "ext_channel.h"
#include "server.h"
#include "timeval.h"
#include "channel_credentials.h"
#include "call_credentials.h"
#include "server_credentials.h"
#include "completion_queue.h"

#include <ext/spl/spl_exceptions.h>
#include <zend_exceptions.h>

ZEND_DECLARE_MODULE_GLOBALS(grpc)
static PHP_GINIT_FUNCTION(grpc);
HashTable grpc_persistent_list;
HashTable grpc_target_upper_bound_map;

// Global variables for gcp extension
int grpc_gcp_extension;
HashTable grpc_gcp_config;
int channel_pool_size;
grpc_gcp_channel* ext_channel;

/* {{{ grpc_functions[]
 *
 * Every user visible function must have an entry in grpc_functions[].
 */
// Todo: This method will be moved to another extension, which can set the
// flag `grpc_gcp_extension` inside this extension.
PHP_FUNCTION(enable_grpc_gcp) {
  if (grpc_gcp_extension) {
    // Only read config once. In PHP-FPM, the same script with `enable_grpc_gcp`
    // function may run multiple times.
    // It is time consuming to parse config in every scripts.
    return;
  }
  grpc_gcp_extension = 1;
  zend_hash_init_ex(&grpc_gcp_config, 20, NULL,
                    EG(persistent_list).pDestructor, 1, 0);
  php_printf("grpc_gcp_extension %d\n", grpc_gcp_extension);
  /* "a" == 1 array */
  zval *config_array = NULL;
  if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "a", &config_array)
      == FAILURE) {
    zend_throw_exception(spl_ce_InvalidArgumentException,
                         "enable_grpc_gcp expects an array of config", 1 TSRMLS_CC);
    return;
  }
  HashTable *grpc_gcp_config_hash = Z_ARRVAL_P(config_array);
  char *key = NULL;
  zval *data;
  int key_type;
  PHP_GRPC_HASH_FOREACH_STR_KEY_VAL_START(grpc_gcp_config_hash, key, key_type, data)
    if (key_type) {}
    // api
    zval *data2;
    PHP_GRPC_HASH_FOREACH_STR_KEY_VAL_START(Z_ARRVAL_P(data), key, key_type, data2)
      // api: target
      // channel_pool
      if(strcmp(key, "channel_pool") == 0){
        zval* pool_data;
        PHP_GRPC_HASH_FOREACH_VAL_START(Z_ARRVAL_P(data2), pool_data)
          channel_pool_size = atoi(Z_STRVAL_P(pool_data));
        PHP_GRPC_HASH_FOREACH_END()
      }
      // method
      if(strcmp(key, "method") == 0){
      zval *data3;
      PHP_GRPC_HASH_FOREACH_STR_KEY_VAL_START(Z_ARRVAL_P(data2), key, key_type, data3)
        zval *data4;
        char* method_name = NULL;
        char* command = NULL;
        char* affinity_key = NULL;
        PHP_GRPC_HASH_FOREACH_STR_KEY_VAL_START(Z_ARRVAL_P(data3), key, key_type, data4)
          if (strcmp(key, "name") == 0) {
            char* name_read = Z_STRVAL_P(data4);
            // TODO: it should have the allocation by it's own, otherwise it will
            // be in valid under PHP-FPM mode.
            method_name = malloc(strlen(name_read) + 1);
            strcpy(method_name, name_read);
          }
          if (strcmp(key, "affinity") == 0) {
            zval *affinity_val;
            PHP_GRPC_HASH_FOREACH_STR_KEY_VAL_START(Z_ARRVAL_P(data4),
                                                    key, key_type, affinity_val)
              if (strcmp(key, "command") == 0) {
                command = Z_STRVAL_P(affinity_val);
              }
              if (strcmp(key, "affinity_key") == 0) {
                affinity_key = Z_STRVAL_P(affinity_val);
              }
              if (affinity_val) {}
            PHP_GRPC_HASH_FOREACH_END()
            affinity* aff = malloc(sizeof(affinity));
            aff->command = command;
            aff->affinity_key = affinity_key;
            php_grpc_zend_resource new_rsrc;
            new_rsrc.ptr = aff;
            php_printf("%s: %s=>%s\n", method_name, command, affinity_key);
            PHP_GRPC_PERSISTENT_LIST_UPDATE(&grpc_gcp_config,
                method_name, strlen(method_name), (void *)&new_rsrc);
          }
        PHP_GRPC_HASH_FOREACH_END()
      PHP_GRPC_HASH_FOREACH_END()
      }
    PHP_GRPC_HASH_FOREACH_END()
  PHP_GRPC_HASH_FOREACH_END()
}

PHP_FUNCTION(print_ext_channels) {
  php_printf("print_ext_channels\n");
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_enable_grpc_gcp, 0, 0, 0)
  ZEND_ARG_INFO(0, try_to_connect)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_print_ext_channels, 0, 0, 0)
ZEND_END_ARG_INFO()

const zend_function_entry grpc_functions[] = {
  PHP_FE(print_ext_channels, arginfo_print_ext_channels)
  PHP_FE(enable_grpc_gcp, arginfo_enable_grpc_gcp)
  PHP_FE_END /* Must be the last line in grpc_functions[] */
};
/* }}} */

/* {{{ grpc_module_entry
 */
zend_module_entry grpc_module_entry = {
  STANDARD_MODULE_HEADER,
  "grpc",
  grpc_functions,
  PHP_MINIT(grpc),
  PHP_MSHUTDOWN(grpc),
  PHP_RINIT(grpc),
  NULL,
  PHP_MINFO(grpc),
  PHP_GRPC_VERSION,
  PHP_MODULE_GLOBALS(grpc),
  PHP_GINIT(grpc),
  NULL,
  NULL,
  STANDARD_MODULE_PROPERTIES_EX};
/* }}} */

#ifdef COMPILE_DL_GRPC
ZEND_GET_MODULE(grpc)
#endif

/* {{{ PHP_INI
 */
/* Remove comments and fill if you need to have entries in php.ini
   PHP_INI_BEGIN()
   STD_PHP_INI_ENTRY("grpc.global_value", "42", PHP_INI_ALL, OnUpdateLong,
                     global_value, zend_grpc_globals, grpc_globals)
   STD_PHP_INI_ENTRY("grpc.global_string", "foobar", PHP_INI_ALL,
                     OnUpdateString, global_string, zend_grpc_globals,
                     grpc_globals)
   PHP_INI_END()
*/
/* }}} */

/* {{{ php_grpc_init_globals
 */
/* Uncomment this function if you have INI entries
   static void php_grpc_init_globals(zend_grpc_globals *grpc_globals)
   {
     grpc_globals->global_value = 0;
     grpc_globals->global_string = NULL;
   }
*/
/* }}} */

/* {{{ PHP_MINIT_FUNCTION
 */
PHP_MINIT_FUNCTION(grpc) {
  /* If you have INI entries, uncomment these lines
     REGISTER_INI_ENTRIES();
  */
  /* Register call error constants */
  REGISTER_LONG_CONSTANT("Grpc\\CALL_OK", GRPC_CALL_OK,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CALL_ERROR", GRPC_CALL_ERROR,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CALL_ERROR_NOT_ON_SERVER",
                         GRPC_CALL_ERROR_NOT_ON_SERVER,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CALL_ERROR_NOT_ON_CLIENT",
                         GRPC_CALL_ERROR_NOT_ON_CLIENT,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CALL_ERROR_ALREADY_INVOKED",
                         GRPC_CALL_ERROR_ALREADY_INVOKED,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CALL_ERROR_NOT_INVOKED",
                         GRPC_CALL_ERROR_NOT_INVOKED,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CALL_ERROR_ALREADY_FINISHED",
                         GRPC_CALL_ERROR_ALREADY_FINISHED,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CALL_ERROR_TOO_MANY_OPERATIONS",
                         GRPC_CALL_ERROR_TOO_MANY_OPERATIONS,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CALL_ERROR_INVALID_FLAGS",
                         GRPC_CALL_ERROR_INVALID_FLAGS,
                         CONST_CS | CONST_PERSISTENT);

  /* Register flag constants */
  REGISTER_LONG_CONSTANT("Grpc\\WRITE_BUFFER_HINT", GRPC_WRITE_BUFFER_HINT,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\WRITE_NO_COMPRESS", GRPC_WRITE_NO_COMPRESS,
                         CONST_CS | CONST_PERSISTENT);

  /* Register status constants */
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_OK", GRPC_STATUS_OK,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_CANCELLED", GRPC_STATUS_CANCELLED,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_UNKNOWN", GRPC_STATUS_UNKNOWN,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_INVALID_ARGUMENT",
                         GRPC_STATUS_INVALID_ARGUMENT,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_DEADLINE_EXCEEDED",
                         GRPC_STATUS_DEADLINE_EXCEEDED,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_NOT_FOUND", GRPC_STATUS_NOT_FOUND,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_ALREADY_EXISTS",
                         GRPC_STATUS_ALREADY_EXISTS,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_PERMISSION_DENIED",
                         GRPC_STATUS_PERMISSION_DENIED,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_UNAUTHENTICATED",
                         GRPC_STATUS_UNAUTHENTICATED,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_RESOURCE_EXHAUSTED",
                         GRPC_STATUS_RESOURCE_EXHAUSTED,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_FAILED_PRECONDITION",
                         GRPC_STATUS_FAILED_PRECONDITION,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_ABORTED", GRPC_STATUS_ABORTED,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_OUT_OF_RANGE",
                         GRPC_STATUS_OUT_OF_RANGE,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_UNIMPLEMENTED",
                         GRPC_STATUS_UNIMPLEMENTED,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_INTERNAL", GRPC_STATUS_INTERNAL,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_UNAVAILABLE", GRPC_STATUS_UNAVAILABLE,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\STATUS_DATA_LOSS", GRPC_STATUS_DATA_LOSS,
                         CONST_CS | CONST_PERSISTENT);

  /* Register op type constants */
  REGISTER_LONG_CONSTANT("Grpc\\OP_SEND_INITIAL_METADATA",
                         GRPC_OP_SEND_INITIAL_METADATA,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\OP_SEND_MESSAGE",
                         GRPC_OP_SEND_MESSAGE,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\OP_SEND_CLOSE_FROM_CLIENT",
                         GRPC_OP_SEND_CLOSE_FROM_CLIENT,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\OP_SEND_STATUS_FROM_SERVER",
                         GRPC_OP_SEND_STATUS_FROM_SERVER,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\OP_RECV_INITIAL_METADATA",
                         GRPC_OP_RECV_INITIAL_METADATA,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\OP_RECV_MESSAGE",
                         GRPC_OP_RECV_MESSAGE,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\OP_RECV_STATUS_ON_CLIENT",
                         GRPC_OP_RECV_STATUS_ON_CLIENT,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\OP_RECV_CLOSE_ON_SERVER",
                         GRPC_OP_RECV_CLOSE_ON_SERVER,
                         CONST_CS | CONST_PERSISTENT);

  /* Register connectivity state constants */
  REGISTER_LONG_CONSTANT("Grpc\\CHANNEL_IDLE",
                         GRPC_CHANNEL_IDLE,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CHANNEL_CONNECTING",
                         GRPC_CHANNEL_CONNECTING,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CHANNEL_READY",
                         GRPC_CHANNEL_READY,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CHANNEL_TRANSIENT_FAILURE",
                         GRPC_CHANNEL_TRANSIENT_FAILURE,
                         CONST_CS | CONST_PERSISTENT);
  REGISTER_LONG_CONSTANT("Grpc\\CHANNEL_FATAL_FAILURE",
                         GRPC_CHANNEL_SHUTDOWN,
                         CONST_CS | CONST_PERSISTENT);

  grpc_init_call(TSRMLS_C);
  GRPC_STARTUP(channel);
  grpc_init_server(TSRMLS_C);
  grpc_init_timeval(TSRMLS_C);
  grpc_init_channel_credentials(TSRMLS_C);
  grpc_init_call_credentials(TSRMLS_C);
  grpc_init_server_credentials(TSRMLS_C);
  grpc_gcp_extension = false;
  return SUCCESS;
}
/* }}} */


/* {{{ PHP_MSHUTDOWN_FUNCTION
 */
PHP_MSHUTDOWN_FUNCTION(grpc) {
  /* uncomment this line if you have INI entries
     UNREGISTER_INI_ENTRIES();
  */
  // WARNING: This function IS being called by PHP when the extension
  // is unloaded but the logs were somehow suppressed.
  if (GRPC_G(initialized)) {
    zend_hash_clean(&grpc_persistent_list);
    zend_hash_destroy(&grpc_persistent_list);
    zend_hash_clean(&grpc_target_upper_bound_map);
    zend_hash_destroy(&grpc_target_upper_bound_map);
    grpc_shutdown_timeval(TSRMLS_C);
    grpc_php_shutdown_completion_queue(TSRMLS_C);
    grpc_shutdown();
    GRPC_G(initialized) = 0;
  }
  return SUCCESS;
}
/* }}} */

/* {{{ PHP_MINFO_FUNCTION
 */
PHP_MINFO_FUNCTION(grpc) {
  php_info_print_table_start();
  php_info_print_table_row(2, "grpc support", "enabled");
  php_info_print_table_row(2, "grpc module version", PHP_GRPC_VERSION);
  php_info_print_table_end();
  /* Remove comments if you have entries in php.ini
     DISPLAY_INI_ENTRIES();
  */
}
/* }}} */

/* {{{ PHP_RINIT_FUNCTION
 */
PHP_RINIT_FUNCTION(grpc) {
  if (!GRPC_G(initialized)) {
    grpc_init();
    grpc_php_init_completion_queue(TSRMLS_C);
    GRPC_G(initialized) = 1;
  }
  return SUCCESS;
}
/* }}} */

/* {{{ PHP_GINIT_FUNCTION
 */
static PHP_GINIT_FUNCTION(grpc) {
  grpc_globals->initialized = 0;
}
/* }}} */

/* The previous line is meant for vim and emacs, so it can correctly fold and
   unfold functions in source code. See the corresponding marks just before
   function definition, where the functions purpose is also documented. Please
   follow this convention for the convenience of others editing your code.
*/
