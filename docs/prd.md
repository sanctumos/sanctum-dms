# 📘 Product Requirements Document (PRD)

**Project:** Dealer Management System (DMS)
**Version:** 1.0
**Date:** October 2025
**Author:** [Sanctum Engineering / RizznOps]
**Status:** Ready for Engineering Execution

---

## 1. Overview

The **Dealer Management System (DMS)** is a standalone but Sanctum-compatible application designed to manage the acquisition, inventory, and sale of vehicles across multiple dealer entities. It mirrors the **Best Jobs in TA CRM** architecture — PHP + SQLite, API-first, test-driven — ensuring shared development standards and interoperability.

The DMS serves as a **transactional intelligence system** for physical inventory, complementing the CRM (human relationships) and providing structured data for Sanctum analytics and compliance agents.

---

## 2. Objectives

**Primary Objectives:**

1. Provide a fully functional RESTful API for managing dealers, vehicles, and sales.
2. Support hybrid data access (direct DB reads, API writes).
3. Maintain parity with CRM codebase for modular reusability.
4. Establish testing, security, and environment management from day one.
5. Lay groundwork for SDK and MCP integration.

**Secondary Objectives:**

* Enable compliance tracking (per-entity sales limit).
* Surface metrics for profitability and operational intelligence.
* Provide OpenAPI documentation for seamless Sanctum integration.

---

## 3. System Architecture

### 3.1 Architectural Pattern

* **Language:** PHP 8.0+
* **Database:** SQLite3 (native extension, no PDO)
* **Architecture:** API-first, MVC-like modular design
* **Web Server:** Nginx (preferred)
* **Frontend:** Bootstrap 5, minimal UI (optional Phase 2)
* **Testing:** Custom + PHPUnit integration

### 3.2 Directory Layout

```
dms/
├── public/                    # Web root (only public files)
│   ├── index.php             # Main entry point
│   ├── router.php            # Simple routing logic
│   ├── api/
│   │   └── v1/
│   │       └── index.php     # RESTful API endpoint
│   ├── pages/                # Web interface pages (future)
│   ├── assets/               # Static resources
│   └── includes/             # Shared PHP components
├── includes/                  # Private PHP includes
│   ├── config.php            # Application configuration
│   ├── database.php          # Database handler
│   ├── auth.php              # Authentication system
│   ├── schema_definitions.php # Canonical schema definitions
│   ├── services/
│   │   ├── DealerManagementService.php
│   │   ├── VehicleService.php
│   │   └── SaleService.php
│   ├── utils/
│   └── middleware/
├── db/                       # SQLite database (private)
│   └── dms.db
├── tests/                    # Comprehensive test suite
│   ├── bootstrap.php         # Test environment setup
│   ├── run_tests.php         # Main test runner
│   ├── unit/                 # Unit tests
│   ├── api/                  # API integration tests
│   ├── integration/          # Integration tests
│   └── e2e/                  # End-to-end tests
├── docs/                     # Documentation
│   ├── openapi.json
│   └── README.md
├── init.php                  # CLI initialization script
└── upgrade.php               # CLI upgrade script
```

---

## 4. Core Features

### 4.1 Dealer Management

* Create, update, and deactivate dealers.
* Each dealer can manage multiple vehicles.
* Dealers tracked for compliance (max 4 retail sales per year if non-licensed).

**Endpoints:**

```
GET    /api/v1/dealers
POST   /api/v1/dealers
GET    /api/v1/dealers/{id}
PUT    /api/v1/dealers/{id}
DELETE /api/v1/dealers/{id}
```

**Data Model:**

| Field          | Type     | Notes                     |
| -------------- | -------- | ------------------------- |
| id             | INT (PK) | Auto-increment            |
| name           | TEXT     | Dealer name               |
| code           | TEXT     | Unique short code         |
| address        | TEXT     | Optional                  |
| phone          | TEXT     | Optional                  |
| email          | TEXT     | Optional                  |
| contact_person | TEXT     | Optional                  |
| status         | TEXT     | Enum('active','inactive') |
| created_at     | DATETIME | Auto                      |
| updated_at     | DATETIME | Auto                      |

---

### 4.2 Vehicle Management

* Add and track vehicles per dealer.
* Update vehicle details and status through lifecycle.
* Vehicle lifecycle: `available → under_contract → sold → archived`.

**Endpoints:**

```
GET    /api/v1/vehicles
POST   /api/v1/vehicles
GET    /api/v1/vehicles/{id}
PUT    /api/v1/vehicles/{id}
DELETE /api/v1/vehicles/{id}
```

**Data Model:**

| Field      | Type          | Notes                               |
| ---------- | ------------- | ----------------------------------- |
| id         | INT (PK)      | Auto                                |
| dealer_id  | INT (FK)      | Linked to dealers                   |
| vin        | TEXT (17)     | Unique identifier                   |
| make       | TEXT          | Required                            |
| model      | TEXT          | Required                            |
| year       | INT           | Required                            |
| color      | TEXT          | Optional                            |
| price      | DECIMAL(10,2) | Optional                            |
| status     | TEXT          | Enum('available','sold','archived') |
| created_at | DATETIME      | Auto                                |
| updated_at | DATETIME      | Auto                                |

---

### 4.3 Sales Management

* Record sales, associate with vehicle, dealer, and customer.
* Automatically update vehicle status to `sold`.
* Calculate margin and commissions.

**Endpoints:**

```
GET    /api/v1/sales
POST   /api/v1/sales
GET    /api/v1/sales/{id}
PUT    /api/v1/sales/{id}
DELETE /api/v1/sales/{id}
```

**Data Model:**

| Field       | Type          | Notes                          |
| ----------- | ------------- | ------------------------------ |
| id          | INT (PK)      | Auto                           |
| dealer_id   | INT (FK)      | Dealer                         |
| vehicle_id  | INT (FK)      | Vehicle                        |
| customer_id | INT (FK)      | Optional for later CRM linkage |
| sale_price  | DECIMAL(10,2) | Required                       |
| sale_date   | DATE          | Required                       |
| salesperson | TEXT          | Optional                       |
| commission  | DECIMAL(10,2) | Optional                       |
| status      | TEXT          | Enum('completed','voided')     |
| created_at  | DATETIME      | Auto                           |
| updated_at  | DATETIME      | Auto                           |

---

### 4.4 Security & Authentication

* **Bearer Token** for API access.
* **Session Auth** for web.
* **Role-based access**: `admin`, `dealer`, `read-only`.
* **Rate limiting** and CSRF protection.

---

## 5. Non-Functional Requirements

| Category            | Requirement                                        |
| ------------------- | -------------------------------------------------- |
| **Performance**     | <150ms avg response time for CRUD ops              |
| **Scalability**     | SQLite for local; PostgreSQL-ready later           |
| **Security**        | Multi-layer auth, input sanitation, strict headers |
| **Portability**     | Works under Apache, Nginx, or PHP built-in         |
| **Maintainability** | No external dependencies, modular service design   |
| **Testing**         | >85% coverage target                               |

---

## 6. Integration Roadmap (Post-Core)

| Phase   | Deliverable           | Description                                             |
| ------- | --------------------- | ------------------------------------------------------- |
| **2.1** | Reporting API         | `/api/v1/reports/inventory` and `/api/v1/reports/sales` |
| **2.2** | Compliance API        | `/api/v1/audit/entities` for SMCP integration           |
| **3.0** | Sanctum SDKs          | PHP and Python SDKs wrapping REST API                   |
| **3.1** | MCP Server            | Declarative schema + command/action metadata            |
| **4.0** | Sanctum Agent Linkage | Dream Agent reads DMS records for analysis              |

---

## 7. Testing Strategy

### Categories:

1. **Unit Tests** → Service layer functions
2. **API Tests** → Endpoint behavior + auth
3. **Integration Tests** → Full record flow
4. **E2E Tests** → Simulated dealer-to-sale workflow

**Test DB:** `db/test_dms.db`
**Coverage Goal:** 85%+
**Mock Services:** Auth, DB, and config mocks matching CRM.

---

## 8. Deployment & Environment

**Environments:**

* `dev` (Windows) → debug on
* `prod` (Ubuntu/Nginx) → secure headers, debug off

**Deployment Steps:**

1. Pull repo.
2. Initialize DB: `php init.php init`.
3. Verify via `/api/v1/status`.

**Nginx Config:** identical to CRM, substituting `/dms/public` as root.

**Security:** Block access to private directories (`/includes`, `/db`, `/tests`, `/docs`).

---

## 9. Documentation

* **OpenAPI 3.0** specification in `/docs/openapi.json`
* **README.md** with setup, usage, and test instructions
* **Inline Docblocks** for every public function

---

## 10. Success Criteria

| Category           | Metric                    | Target            |
| ------------------ | ------------------------- | ----------------- |
| **Reliability**    | API uptime                | ≥ 99.9%           |
| **Data Integrity** | DB constraint errors      | 0 in test suite   |
| **Test Coverage**  | Code coverage             | ≥ 85%             |
| **Security**       | Open ports / exposed dirs | 0 vulnerabilities |
| **Compatibility**  | Sanctum SDK integration   | Full parity       |

---

## 11. Deliverables Checklist (for dev swarm)

✅ Repo scaffold (PHP 8 + SQLite + API routing)
✅ Database schema and migration scripts
✅ Authentication + rate limiting
✅ Test suite + runner
✅ CLI tools (init.php, upgrade.php)
✅ Status API endpoint
🔄 Dealer / Vehicle / Sale services (Phase 2)
🔄 RESTful API with standardized responses (Phase 2)
🔄 OpenAPI spec (Phase 2)
🔄 Deployment docs (Phase 2)

---

## 12. Governance & Versioning

| Field               | Description                                   |
| ------------------- | --------------------------------------------- |
| **Repo**            | `sanctum-dms`                                 |
| **Versioning**      | Semantic (v1.0.0 = first stable API parity)   |
| **Branching**       | `main`, `dev`, `feature/*`, `hotfix/*`        |
| **Ownership**       | RizznOps Core / Sanctum Engineering           |
| **Review Policy**   | PR required for all merges to `main`          |
| **Release Cadence** | Weekly builds, stable tagged releases monthly |