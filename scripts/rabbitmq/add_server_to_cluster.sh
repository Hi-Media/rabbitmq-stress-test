#!/usr/bin/env bash

#
# Add a rabbitmq instance to a cluster.
#
# @param $1 Rabbitmq server address to add to a cluster. Format: '[user@]IP'.
# @param $2 Rabbitmq cluster's name.
#

# [user@]IP
SERVER="$1"

# IP
CLUSTER_NODE="$2"

ssh -T "$SERVER" <<EOT
    rabbitmqctl stop_app
    rabbitmqctl join_cluster rabbit@$CLUSTER_NODE
    rabbitmqctl start_app
EOT
