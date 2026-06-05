# Paylo — Admin Panel Tamamlanma Roadmap-ı

**Tarix:** 2026-06-05 · **Status:** icra başlanır
**Məqsəd:** Admin panelindəki "Tezliklə" (disabled) nav elementlərini düzgün sıra ilə,
backend hazırlığına görə prioritetləyərək real işlək səhifələrə çevirmək.

> **Prinsip:** hər element = Route + Controller + (Form)Request + Vue səhifə + Test +
> nav-da aktivləşdirmə. Sadəcə `disabled` flag-i götürmək DEYİL — arxasında real
> implementasiya olmalıdır (audit FE-3 qaydası: boş/sınıq səhifə göstərmə).

---

## 1. Cari vəziyyət (`resources/js/Layouts/AdminLayout.vue`)

**✅ Aktiv (route + Vue səhifə var, işləyir):**
- Dashboard · Ledger (+ entry detail) · Merchants (+ CRUD form + detail)

**🔒 "Tezliklə" (disabled, UI yox) — 12 element:**
Analytics · Per-merchant Buckets · Redemptions · Refunds · Rules · Category Tiers ·
Campaigns · Users · Fraud Signals · Audit Logs · Settlements · Manual Adj.

**Gizli boşluqlar (backend var, UI/nav yox):**
- `admin.transactions` (+ reverse) — route + controller var, `Admin/Transactions.vue` YOXDUR.
- `admin.bonus-adjustments` (CANON-4) — endpoint + 9 test var, UI YOXDUR.

---

## 2. Backend-hazırlığına görə kateqoriyalar

| Kateqoriya | Elementlər | İş həcmi |
|---|---|---|
| **A — Backend HAZIR, yalnız UI** | Manual Adj. (CANON-4), Transactions (reverse) | Kiçik |
| **B — Mövcud datadan read-view** | Per-merchant Buckets, Users, Redemptions, Refunds, Settlements | Orta |
| **C — Audit store lazım** | Audit Logs (DB-backed `audit_logs` cədvəli) | Orta-böyük |
| **D — Sıfırdan feature (ayrıca spec)** | Analytics, Rules, Category Tiers, Campaigns, Fraud Signals | Böyük |

---

## 3. İcra sırası (faza-faza, ardıcıl)

### Phase 1 — Backend hazır olanları aktivləşdir (sürətli, yüksək dəyər)
- [x] **1.1 Manual Adj.** ✅ — `Admin/BonusAdjustment.vue` (email ilə müştəri + merchant
  dropdown + AZN→qəpik + səbəb), GET `create` route, store() Inertia/JSON branch
  (email→customer resolve), nav aktivləşdirildi. **13 test PASS.** `npm run build` edildi.
- [x] **1.2 Transactions** ✅ — `Admin/Transactions.vue` (cədvəl + status/receipt filter +
  pagination + reverse modal), nav-a əlavə edildi, `reverse()` Inertia/JSON branch (redirect+flash).
  Phase 1.1 flash `status`→`success` düzəldildi (HandleInertiaRequests `flash.success`). **5 yeni test PASS.**

### Phase 2 — Mövcud data üzərində read/manage view-lar (orta)
- [x] **2.1 Per-merchant Buckets** ✅ — `BucketController` + `Admin/Buckets.vue` (cədvəl: user×merchant
  balance/earned/redeemed/expired/last_activity, merchant+user filter, pagination, cəm bloklanmış balans).
  Read-only. Nav aktiv. **4 test PASS.** (Admin tam görünüş — maskalama lazım deyil.)
- [x] **2.2 Users** ✅ — `UserController` + `Admin/Users.vue`: bütün user siyahısı (ad/email, rol,
  merchant, aktivlik, qeydiyyat), filter (rol / aktivlik / ad-email axtarış), pagination,
  `is_active` toggle (təsdiq modalı). Deaktiv = `EnsureUserIsActive` blok + token revoke (anonimləşdirmə
  YOX). Privilege: admin özünü dəyişə bilməz. Nav aktiv. **9 test PASS.**
- [x] **2.3 Redemptions + Refunds** ✅ — yoxlanıldı: `LedgerController` + `Admin/Ledger.vue` artıq
  tam `type` filtrinə malik idi. Ona görə yalnız **preset nav linkləri** əlavə edildi
  (Redemptions→`?type=redeem`, Refunds→`?type=refund`) + URL-əsaslı `:active` highlight +
  dinamik breadcrumb. Yeni səhifə/controller yox. **2 filter testi PASS** (filtri kilidləyir).
- [x] **2.4 Settlements** ✅ — reconcile məntiqi `SettlementReconciler` servisinə çıxarıldı
  (CLI cron + HTTP eyni mənbə, drift dublikatı yox); command ona delegasiya edir (9 köhnə test
  hələ PASS). `SettlementController` + `Admin/Settlements.vue`: scope/merchant filtri, status badge,
  mismatch cədvəli (bucket/user/merchant/sahə/faktiki/gözlənilən/delta), "İndi işlət (qeydə al)"
  düyməsi (audit). index() read-only (audit yox), run() audit yazır. Nav aktiv. **7 yeni test PASS.**

### Phase 3 — Audit/compliance (store tələb edir)
- [x] **3.1 Audit Logs** ✅ — `audit_logs` migration (append-only, actor_id FK-siz) + `AuditLog`
  modeli (immutable: update/delete throw) + `AuditLogger` **dual-write** (fayl + DB, defensiv
  try/catch + `Schema::hasTable` — PG transaction-poison riski yox, biznes əməliyyatını sındırmır) +
  `AuditLogController` + `Admin/AuditLogs.vue` (event/tarix filtri, aktor, kontekst). Nav aktiv.
  **5 yeni test PASS** (dual-write, render, filter, authz, immutability). 54 mövcud audit call-site regressiyasız.

### Phase 4 — Yeni feature-lər (hər biri ayrıca spec + sprint)
- [ ] **4.1 Analytics** — dərin dashboard (qrafiklər, trend). Mövcud Dashboard stats-ı genişləndirir.
- [ ] **4.2 Rules** — earn faizləri (`config/loyalty.php`) admin-redaktə (DB-backed config). Merchant
  self-service config (deep-audit S7-3) ilə bağlıdır.
- [ ] **4.3 Category Tiers** — tier multiplier-ləri admin-redaktə (Rules ilə eyni model).
- [ ] **4.4 Campaigns** — promosyon kampaniyaları (yeni model + məntiq + UI).
- [ ] **4.5 Fraud Signals** — fraud aşkarlama (yeni məntiq + UI).

---

## 4. Hər implementasiyada riayət olunacaq
- Azərbaycanca komentariya, `declare(strict_types=1)`, integer qəpik.
- Hər səhifə üçün **feature test** (rol → status, render, data leak yox).
- Yalnız `role:admin` route qrupunda.
- Nav-da aktivləşdirmə: `AdminLayout.vue`-də `disabled` + `badge="Tezliklə"` götür, `:href="route(...)"` qoy.
- PII: telefon/email maska qaydaları (PiiMasker), cross-merchant leak yoxlaması.
- Tamamlanan element bu sənəddə `[x]` işarələnir + qısa qeyd.

---

## 5. Status jurnalı
| Faza | Element | Status | Qeyd |
|---|---|---|---|
| 1.1 | Manual Adj. UI | ✅ DONE | 13 test PASS, build edildi, nav aktiv |
| 1.2 | Transactions UI | ✅ DONE | 5 test PASS, reverse modal, nav aktiv |
| 2.1 | Per-merchant Buckets | ✅ DONE | 4 test PASS, read-view, nav aktiv |
| 2.2 | Users | ✅ DONE | 9 test PASS, toggle + filterlər, self-guard, nav aktiv |
| 2.3 | Redemptions/Refunds | ✅ DONE | 2 test PASS, preset nav linkləri (mövcud filtr) |
| 2.4 | Settlements | ✅ DONE | 7 test PASS, servis refactor, "İndi işlət" + audit |
| — | **Phase 2 TAM BİTDİ** | ✅ | Buckets + Users + Redemptions/Refunds + Settlements |
| 3.1 | Audit Logs | ✅ DONE | 5 test PASS, dual-write, immutable store, nav aktiv |
| 4.x | Analytics/Rules/Tiers/Campaigns/Fraud | ⏳ növbəti | hər biri ayrıca spec |
| 4.x | Analytics/Rules/Tiers/Campaigns/Fraud | ⏳ | ayrıca spec |
