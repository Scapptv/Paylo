import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';
import 'package:paylo/shared/widgets/app_button.dart';
import 'package:paylo/shared/widgets/brand_mark.dart';

class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  bool _obscure = true;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();

    // Cache notifier/router/messenger BEFORE await — widget dispose olsa belə yaşayır.
    final notifier = ref.read(authControllerProvider.notifier);
    final router = GoRouter.of(context);
    final messenger = ScaffoldMessenger.of(context);

    await notifier.register(
          name: _name.text.trim(),
          email: _email.text.trim(),
          phone: _phone.text.trim(),
          password: _password.text,
        );

    final state = notifier.state;

    if (state is AuthAuthenticated) {
      router.go('/wallet');
    } else if (state is AuthError) {
      _showSnack(messenger, state.exception);
      notifier.clearError();
    }
  }

  void _showSnack(ScaffoldMessengerState messenger, ApiException e) {
    final message = switch (e) {
      ValidationException v => v.firstError() ?? v.message,
      _ => e.message,
    };
    messenger.showSnackBar(SnackBar(
      content: Text(message),
      backgroundColor: AppColors.danger,
      behavior: SnackBarBehavior.floating,
    ),);
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(authControllerProvider);
    final isLoading = state is AuthLoading;

    return Scaffold(
      appBar: AppBar(
        leading: IconButton(icon: const Icon(Icons.arrow_back), onPressed: () => context.go('/login')),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 32),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const BrandMark(),
                const SizedBox(height: 32),
                Text('02 / Sign up', style: AppTextStyles.mono(10, letterSpacing: 0.2)),
                const SizedBox(height: 12),
                Text.rich(TextSpan(
                  style: AppTextStyles.display(32, weight: FontWeight.w300),
                  children: [
                    const TextSpan(text: 'Wallet '),
                    TextSpan(
                      text: 'yarat',
                      style: AppTextStyles.display(32, weight: FontWeight.w600, color: AppColors.accent)
                          .copyWith(fontStyle: FontStyle.italic),
                    ),
                    const TextSpan(text: '.'),
                  ],
                ),),

                const SizedBox(height: 32),

                _label('Ad'),
                TextFormField(
                  controller: _name,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(hintText: 'Aysel Hüseynova'),
                  validator: (v) => (v == null || v.trim().length < 2) ? 'Minimum 2 simvol' : null,
                ),
                const SizedBox(height: 16),

                _label('E-poçt'),
                TextFormField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  textInputAction: TextInputAction.next,
                  autocorrect: false,
                  decoration: const InputDecoration(hintText: 'aysel@gmail.com'),
                  validator: (v) {
                    if (v == null || v.trim().isEmpty) return 'E-poçt tələb olunur';
                    if (!v.contains('@')) return 'Yanlış e-poçt';
                    return null;
                  },
                ),
                const SizedBox(height: 16),

                _label('Telefon'),
                TextFormField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(hintText: '+994501234567'),
                  validator: (v) {
                    if (v == null || v.trim().length < 9) return 'Yanlış nömrə';
                    return null;
                  },
                ),
                const SizedBox(height: 16),

                _label('Şifrə'),
                TextFormField(
                  controller: _password,
                  obscureText: _obscure,
                  textInputAction: TextInputAction.done,
                  onFieldSubmitted: (_) => _submit(),
                  decoration: InputDecoration(
                    hintText: 'Minimum 8 simvol, hərf və rəqəm',
                    suffixIcon: IconButton(
                      icon: Icon(_obscure ? Icons.visibility_off : Icons.visibility, size: 18),
                      onPressed: () => setState(() => _obscure = !_obscure),
                    ),
                  ),
                  validator: (v) {
                    if (v == null || v.length < 8) return 'Minimum 8 simvol';
                    if (!RegExp(r'[A-Za-z]').hasMatch(v)) return 'Ən azı 1 hərf';
                    if (!RegExp(r'[0-9]').hasMatch(v)) return 'Ən azı 1 rəqəm';
                    return null;
                  },
                ),

                const SizedBox(height: 32),
                AppButton(label: 'Qeydiyyatdan keç →', loading: isLoading, onPressed: _submit),

                const SizedBox(height: 16),
                Text(
                  'Davam etməklə Şərt və Şərtlərlə razılaşırsınız.',
                  style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.16),
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _label(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(text.toUpperCase(), style: AppTextStyles.mono(10, letterSpacing: 0.2)),
      );
}
