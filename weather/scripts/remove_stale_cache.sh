#!/bin/bash
#
# Cache Cleanup Script
#
# Description:
#   This script is designed to clean up old cache files from the weather application's cache directory.
#   It calculates the number of JSON cache files before and after the cleanup process, 
#   deletes files that are older than 1 day, and logs the activity.
#
# Usage:
#   To execute this script, ensure it has executable permissions:
#   chmod +x cleanup_cache.sh
#   Then run the script from anywhere:
#   ./cleanup_cache.sh
#
# Output:
#   The script outputs an HTML paragraph containing the current timestamp,
#   the starting message indicating the cleanup process, and a summary of the
#   initial, remaining, and deleted JSON files in the cache directory.
#
# Note:
#   The script identifies files to be deleted by comparing their modification time
#   against a .gitkeep file in the cache directory, which is temporarily set
#   to reflect "1 day ago" as the threshold for deletion.
#   This approach ensures that only files older than 1 day are targeted for removal,
#   excluding the .gitkeep file itself.
#   find -mtime +1 was found NOT to work on a production server
#
# Author: Christian Prior-Mamulyan
# Version: 1.0
# Date: 2024-03-0
# LICENCE: CC-BY

echo "<p>"
date "+%Y-%m-%d %H:%M:%S"

# Determine the script's directory
SCRIPT_DIR="$(dirname "$0")"

# Define the target cache directory relative to the script's location
TARGET_DIR="${SCRIPT_DIR}/../cache/"

# Calculate number of .json files before deletion
num_files_before=$(find "$TARGET_DIR" -name "*.json" -type f | wc -l)

# Log starting
echo "Starting cache cleanup in $TARGET_DIR"

touch -d "1 day ago" "${TARGET_DIR}/.gitkeep"
# Find and delete .json files older than 1 day in the cache directory
find "$TARGET_DIR" -name "*.json" -type f ! -newer "${TARGET_DIR}/.gitkeep" -print -exec rm {} \;

# Calculate number of .json files after deletion
num_files_after=$(find "$TARGET_DIR" -name "*.json" -type f | wc -l)

# Calculate deleted files
num_files_deleted=$((num_files_before - num_files_after))

# Log summary
echo "Initial .json files: $num_files_before"
echo "Remaining .json files: $num_files_after"
echo "Deleted .json files: $num_files_deleted"
echo "Old .json files (older than 1 day) in $TARGET_DIR have been deleted."
echo "</p>"
