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
- [ ] **1.2 Transactions** — `Admin/Transactions.vue`: satış siyahısı (filter: status/merchant/receipt,
  paginate) + reverse düyməsi (return_receipt_no + reason → mövcud `admin.transactions.reverse`).
  Nav-a əlavə et. Feature test (siyahı + reverse).

### Phase 2 — Mövcud data üzərində read/manage view-lar (orta)
- [ ] **2.1 Per-merchant Buckets** — read-only siyahı: user × merchant, balance/earned/redeemed/expired,
  filter (merchant/user). Yeni controller + Vue. PII maskalama (staff vs admin — onsuz da admin).
- [ ] **2.2 Users** — admin user idarəetməsi: bütün user-lər (rol, is_active, merchant), filter,
  deaktivləşdirmə (anonimləşdirmə deyil — sadə is_active toggle). Privilege qaydaları.
- [ ] **2.3 Redemptions + Refunds** — `Ledger`-də type-filter (earn/redeem/refund/reversal/expire).
  ÖNCƏ yoxla: `Admin/Ledger.vue` artıq type filter dəstəkləyirmi? Dəstəkləyirsə — yalnız
  preset nav linkləri (`?type=redeem` / `?type=refund`); yoxdursa — filter əlavə et.
- [ ] **2.4 Settlements** — `loyalty:settlement-reconcile` command-i üçün HTTP wrapper:
  read-only hesabat səhifəsi (son reconcile nəticəsi, mismatch siyahısı) + "indi işlət" düyməsi.

### Phase 3 — Audit/compliance (store tələb edir)
- [ ] **3.1 Audit Logs** — `AuditLogger` hazırda fayl/channel-a yazır. UI üçün DB-backed
  `audit_logs` cədvəli (migration) + AuditLogger-i ona da yazmaq (dual-write və ya keçid) +
  filterlənən siyahı UI. Diqqət: PII saxlama qaydaları (QR sha256, telefon maska).

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
| 1.2 | Transactions UI | ⏳ növbəti | backend hazır |
| 2.x | Buckets/Users/Redemptions/Settlements | ⏳ | |
| 3.1 | Audit Logs | ⏳ | store lazım |
| 4.x | Analytics/Rules/Tiers/Campaigns/Fraud | ⏳ | ayrıca spec |
