#!/bin/bash

# Laravel Queue Workers - Concurrent Processing Script
# This script starts multiple queue workers to process jobs concurrently

# Configuration
QUEUE_CONNECTION=${QUEUE_CONNECTION:-database}
WORKER_COUNT=${WORKER_COUNT:-4}
TIMEOUT=${TIMEOUT:-300}
MEMORY_LIMIT=${MEMORY_LIMIT:-512}

echo "Starting $WORKER_COUNT Laravel queue workers..."
echo "Queue Connection: $QUEUE_CONNECTION"
echo "Timeout: $TIMEOUT seconds"
echo "Memory Limit: ${MEMORY_LIMIT}M"
echo ""

# Function to start a worker
start_worker() {
    local worker_id=$1
    echo "Starting worker $worker_id..."
    
    php artisan queue:work $QUEUE_CONNECTION \
        --queue=default \
        --timeout=$TIMEOUT \
        --memory=$MEMORY_LIMIT \
        --tries=3 \
        --sleep=3 \
        --verbose &
    
    echo "Worker $worker_id started (PID: $!)"
}

# Start multiple workers
for i in $(seq 1 $WORKER_COUNT); do
    start_worker $i
done

echo ""
echo "All $WORKER_COUNT workers started!"
echo "Press Ctrl+C to stop all workers"

# Wait for all background processes
wait
