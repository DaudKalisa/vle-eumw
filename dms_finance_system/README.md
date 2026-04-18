# Standalone Dissertation + Finance Management System

This folder contains a separate system focused only on dissertation workflow and dissertation finance management.

## What is separated

- Separate folder: `dms_finance_system/`
- Separate database: `dms_finance_db`
- Separate authentication/session keys (`dms_*`)
- Separate users and role permissions

## Roles included

- `admin`
- `research_coordinator`
- `supervisor`
- `finance_officer`
- `student`

## Setup steps

1. Open: `http://localhost/vle-eumw/dms_finance_system/setup.php`
2. Wait for setup success output.
3. Open: `http://localhost/vle-eumw/dms_finance_system/login.php`
4. Login using seeded accounts shown on the setup page.

## Core concepts implemented

- Dissertation lifecycle by phase/status
- Topic and chapter submissions
- Supervisor feedback cycle
- Coordinator approval and supervisor assignment
- Dissertation invoicing and installment payment tracking
- Finance locks for proposal/ethics/final stages

## Important note

This system is intentionally isolated from the main VLE tables and logic. It can run independently as long as MySQL and Apache are running in XAMPP.
