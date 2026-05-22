import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/auth/domain/user.dart';
import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';
import 'package:paylo/features/profile/data/profile_repository.dart';
import 'package:paylo/features/profile/presentation/screens/change_password_screen.dart';
import 'package:paylo/features/profile/presentation/screens/edit_profile_screen.dart';
import 'package:paylo/features/profile/presentation/screens/delete_account_screen.dart';
import 'package:paylo/shared/widgets/glow.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profileAsync = ref.watch(profileProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Profil')),
      body: profileAsync.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.accent)),
        error: (e, _) => Center(child: Text(e.toString(), style: AppTextStyles.body(13, color: AppColors.danger))),
        data: (user) => _ProfileContent(user: user),
      ),
    );
  }
}

class _ProfileContent extends ConsumerWidget {
  const _ProfileContent({required this.user});
  final User user;

  Future<void> _confirmLogout(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        shape: const RoundedRectangleBorder(borderRadius: BorderRadius.zero, side: BorderSide(color: AppColors.border)),
        title: Text('Çıxış etmək?', style: AppTextStyles.display(20, weight: FontWeight.w600)),
        content: Text('Yenidən daxil olmaq üçün e-poçt və şifrə tələb olunacaq.',
            style: AppTextStyles.body(13, color: AppColors.text2),),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text('Ləğv et', style: AppTextStyles.mono(11, color: AppColors.muted, letterSpacing: 0.16))),
          TextButton(onPressed: () => Navigator.pop(ctx, true),  child: Text('Çıxış',   style: AppTextStyles.mono(11, color: AppColors.danger, letterSpacing: 0.16))),
        ],
      ),
    );

    if (confirmed == true) {
      await ref.read(authControllerProvider.notifier).logout();
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
      children: [
        // User card
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: AppColors.surface,
            border: Border.all(color: AppColors.border2),
            gradient: const RadialGradient(
              center: Alignment(-1.1, -1),
              radius: 1.3,
              colors: [Color(0x26C8FF3D), AppColors.surface],
            ),
            boxShadow: [
              BoxShadow(
                color: AppColors.accent.withValues(alpha: 0.08),
                blurRadius: 24,
                spreadRadius: -6,
                offset: const Offset(0, 8),
              ),
            ],
          ),
          child: Row(
            children: [
              PulseGlow(
                minBlur: 8, maxBlur: 18, minOpacity: 0.25, maxOpacity: 0.5,
                child: Container(
                  width: 60, height: 60, alignment: Alignment.center,
                  decoration: const BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topLeft, end: Alignment.bottomRight,
                      colors: [AppColors.accent, AppColors.accentOrange],
                    ),
                  ),
                  child: Text(user.initials,
                      style: AppTextStyles.display(22, weight: FontWeight.w800, color: AppColors.bg),),
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(user.name, style: AppTextStyles.display(18, weight: FontWeight.w600), overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 2),
                    Text(user.email, style: AppTextStyles.body(12, color: AppColors.muted), overflow: TextOverflow.ellipsis),
                    if (user.emailVerified) ...[
                      const SizedBox(height: 6),
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.verified, color: AppColors.success, size: 12),
                          const SizedBox(width: 4),
                          Text('TƏSDİQLƏNİB',
                              style: AppTextStyles.mono(9, color: AppColors.success, letterSpacing: 0.2),),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),

        const SizedBox(height: 12),

        // Customer ID kartı — barkod bu ID ilə bağlıdır
        _CustomerIdCard(
          customerId: user.customerQr ?? 'cust_${user.id}',
          dbId: user.id,
        ),

        const SizedBox(height: 8),

        if (!user.emailVerified)
          Container(
            margin: const EdgeInsets.only(top: 4),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppColors.warning.withValues(alpha: 0.1),
              border: Border.all(color: AppColors.warning.withValues(alpha: 0.4)),
            ),
            child: Row(
              children: [
                const Icon(Icons.email_outlined, color: AppColors.warning, size: 18),
                const SizedBox(width: 10),
                Expanded(child: Text('E-poçtunuz təsdiqlənməyib', style: AppTextStyles.body(12, color: AppColors.warning))),
              ],
            ),
          ),

        const SizedBox(height: 24),
        const _SectionLabel('Hesab'),
        _MenuItem(
          icon: Icons.edit_outlined,
          label: 'Profil məlumatları',
          onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => EditProfileScreen(user: user))),
        ),
        _MenuItem(
          icon: Icons.lock_outline,
          label: 'Şifrəni dəyiş',
          onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ChangePasswordScreen())),
        ),

        const SizedBox(height: 16),
        const _SectionLabel('Tətbiq'),
        _MenuItem(icon: Icons.notifications_outlined, label: 'Bildirişlər', onTap: () {}),
        _MenuItem(icon: Icons.language_outlined,      label: 'Dil',         onTap: () {}, trailing: 'Azərbaycanca'),
        _MenuItem(icon: Icons.help_outline,           label: 'Dəstək',      onTap: () {}),
        _MenuItem(icon: Icons.description_outlined,   label: 'İstifadə şərtləri', onTap: () {}),

        const SizedBox(height: 24),
        const _SectionLabel('Təhlükəli zona', color: AppColors.danger),
        _MenuItem(
          icon: Icons.logout,
          label: 'Çıxış',
          color: AppColors.danger,
          onTap: () => _confirmLogout(context, ref),
        ),
        _MenuItem(
          icon: Icons.delete_outline,
          label: 'Hesabı sil',
          color: AppColors.danger,
          onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const DeleteAccountScreen())),
        ),

        const SizedBox(height: 32),
        const _VersionFooter(),
      ],
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text, {this.color});
  final String text;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(left: 4, bottom: 8, top: 4),
      child: Text(
        text.toUpperCase(),
        style: AppTextStyles.mono(10, color: color ?? AppColors.muted, letterSpacing: 0.2, weight: FontWeight.w700),
      ),
    );
  }
}

class _CustomerIdCard extends StatelessWidget {
  const _CustomerIdCard({required this.customerId, required this.dbId});
  final String customerId;
  final int dbId;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      onTap: () {
        Clipboard.setData(ClipboardData(text: customerId));
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: AppColors.surface2,
            content: Text('Müştəri ID kopyalandı', style: AppTextStyles.body(12, color: AppColors.text)),
            duration: const Duration(seconds: 2),
          ),
        );
      },
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AppColors.surface,
          border: Border.all(color: AppColors.accent.withValues(alpha: 0.4)),
          boxShadow: [
            BoxShadow(
              color: AppColors.accent.withValues(alpha: 0.06),
              blurRadius: 18,
              spreadRadius: -4,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Row(
          children: [
            Container(
              width: 36, height: 36, alignment: Alignment.center,
              decoration: BoxDecoration(
                color: AppColors.accent.withValues(alpha: 0.12),
                border: Border.all(color: AppColors.accent.withValues(alpha: 0.4)),
              ),
              child: const Icon(Icons.badge_outlined, color: AppColors.accent, size: 18),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('MÜŞTƏRİ ID',
                      style: AppTextStyles.mono(9, color: AppColors.muted, letterSpacing: 0.24, weight: FontWeight.w700),),
                  const SizedBox(height: 4),
                  Text(customerId,
                      style: AppTextStyles.mono(13, color: AppColors.accent, letterSpacing: 0.16, weight: FontWeight.w700),
                      overflow: TextOverflow.ellipsis,),
                  const SizedBox(height: 2),
                  Text('#$dbId · barkoda bağlı',
                      style: AppTextStyles.body(11, color: AppColors.muted),),
                ],
              ),
            ),
            const Icon(Icons.copy, color: AppColors.muted, size: 16),
          ],
        ),
      ),
    );
  }
}

class _MenuItem extends StatelessWidget {
  const _MenuItem({
    required this.icon,
    required this.label,
    required this.onTap,
    this.trailing,
    this.color,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final String? trailing;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: PressScale(
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
          decoration: BoxDecoration(color: AppColors.surface, border: Border.all(color: AppColors.border)),
          child: Row(
            children: [
              Container(
                width: 30, height: 30, alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: (color ?? AppColors.text2).withValues(alpha: 0.08),
                  border: Border.all(color: (color ?? AppColors.border).withValues(alpha: 0.3)),
                ),
                child: Icon(icon, color: color ?? AppColors.text2, size: 16),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Text(label, style: AppTextStyles.body(14, color: color ?? AppColors.text)),
              ),
              if (trailing != null)
                Text(trailing!, style: AppTextStyles.mono(11, color: AppColors.muted)),
              const SizedBox(width: 8),
              const Icon(Icons.chevron_right, color: AppColors.muted, size: 18),
            ],
          ),
        ),
      ),
    );
  }
}

class _VersionFooter extends StatelessWidget {
  const _VersionFooter();

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<PackageInfo>(
      future: PackageInfo.fromPlatform(),
      builder: (_, snap) {
        final v = snap.data?.version ?? '1.0.0';
        final b = snap.data?.buildNumber ?? '1';
        return Center(
          child: Text('Paylo · v$v ($b)',
              style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.16),),
        );
      },
    );
  }
}
