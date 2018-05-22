#include "ext_channel.h"

// Global variables for gcp extension
extern HashTable grpc_gcp_config;
extern int channel_pool_size;
extern grpc_gcp_channel* ext_channel;

void pre_process(){}
//
void run_post_process(){}

void grpc_gcp_channel_init(
    grpc_gcp_channel* channels,
    char *target,
    grpc_channel_args args,
    wrapped_grpc_channel_credentials *creds) {
  channels->max_size = channel_pool_size;
  channels->max_concurrent_streams_low_watermark = 1;
  channels->target = target;
  channels->options = args;
  channels->credentials = creds;
  channels->affinity_by_method = grpc_gcp_config;
}

void grpc_gcp_bind(grpc_gcp_channel* channels, channel_ref* channel, char* affinity_key) {

}

void grpc_gcp_unbind(grpc_gcp_channel* channels, char* affinity_key) {

}

void grpc_gcp_get_channel_ref(grpc_gcp_channel* channels, char* affinity_key) {

}
