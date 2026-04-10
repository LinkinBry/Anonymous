#!/bin/bash
# Run this script from your project root to fix all index.php redirect references
echo "Patching PHP files..."
find . -name "*.php" -not -name "index.php" -not -name "login.php" -not -name "register.php" | while read f; do
  sed -i 's|Location: index\.php"|Location: login.php"|g' "$f"
  sed -i "s|Location: index\.php'|Location: login.php'|g" "$f"
  sed -i 's|Location: index\.php?timeout=1|Location: login.php?timeout=1|g' "$f"
done
echo "Patching JS files..."
find assets/js -name "*.js" | while read f; do
  sed -i "s|'index\.php?timeout=1'|'login.php?timeout=1'|g" "$f"
done
echo "All done!"