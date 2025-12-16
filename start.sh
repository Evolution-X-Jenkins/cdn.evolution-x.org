#!/bin/bash

# Start PHP development server with filtered logging
# This filters out the "Accepted" and "Closing" connection logs while keeping our custom logs
php -S localhost:8000 router.php 2>&1 | grep -v "Accepted\|Closing\|\[200\]:" &
SERVER_PID=$!

echo "PHP development server started on http://localhost:8000"
echo "Press Ctrl+C to stop the server"

# Keep the script running and handle Ctrl+C gracefully
trap 'echo -e "\nStopping server..."; kill $SERVER_PID; exit 0' INT

# Wait for the background process
wait $SERVER_PID