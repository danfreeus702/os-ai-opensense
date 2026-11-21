#!/usr/bin/env python3
"""
Hermes AI plugin backend script.
Called via configd: configctl hermes <action>
"""
import sys
import json

def main():
    action = sys.argv[1] if len(sys.argv) > 1 else ""

    if action == "enable":
        print(json.dumps({"status": "ok"}))
    elif action == "disable":
        print(json.dumps({"status": "ok"}))
    else:
        print(json.dumps({"error": "Unknown action: " + action}))

if __name__ == "__main__":
    main()
