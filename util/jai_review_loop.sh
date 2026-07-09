#!/bin/bash
# Background worker for JAI's run review queue -- processes one changelog row
# per call to /api/jaiReview.php, paced 30s apart per Joshua's rate-limit request.
while true; do
    curl -sLk localhost/api/jaiReview.php > /dev/null 2>&1
    sleep 30
done
