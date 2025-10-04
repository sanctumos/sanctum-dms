# Sanctum DMS

A Dealer Management System (DMS) built following the architectural principles of the Best Jobs in TA CRM system.

## Overview

This project implements a comprehensive Dealer Management System using an API-first, database-driven architecture pattern. The system is designed to manage dealers, vehicle inventory, sales transactions, and provide comprehensive reporting capabilities.

## Architecture

- **API-First Design**: Complete RESTful API with OpenAPI specification
- **Hybrid Data Access**: Direct SQLite reads + API writes for optimal performance
- **Service-Oriented Architecture**: Modular service layer with dependency injection
- **Security-First Approach**: Multi-layered authentication and authorization
- **Modern Frontend**: Bootstrap 5 with progressive enhancement

## Technology Stack

- **Backend**: PHP 8.0+ with SQLite3
- **Frontend**: Bootstrap 5.x with vanilla JavaScript
- **Database**: SQLite3 (direct extension, no PDO)
- **Testing**: Comprehensive test suite (Unit, Integration, API, E2E)
- **Documentation**: OpenAPI specification

## Project Status

Currently in initial development phase. See `DMS_DESIGN_METHODOLOGY_HANDOFF.md` for detailed architectural analysis and implementation roadmap.

## License

- **Code**: AGPLv3
- **Documentation**: CC-BY-SA
