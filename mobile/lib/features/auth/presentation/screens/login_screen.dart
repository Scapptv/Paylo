import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';
import 'package:paylo/shared/widgets/app_button.dart';
import 'package:paylo/shared/widgets/brand_mark.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _obscure = true;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();

    // Cache notifier BEFORE await — widget dispose olsa belə ref ölmür.
    final notifier = ref.read(authControllerProvider.notifier);
    final router = GoRouter.of(context);
    final messenger = ScaffoldMessenger.of(context);

    await notifier.login(
          email: _email.text.trim(),
          password: _password.text,
        );

    // Notifier-dən birbaşa state oxu — ref istifadə etmə.
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
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 32, 24, 32),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const BrandMark(),
                const SizedBox(height: 48),

                Text('01 / Authentication',
                    style: AppTextStyles.mono(10, letterSpacing: 0.2),),
                const SizedBox(height: 12),
                Text.rich(TextSpan(
                  style: AppTextStyles.display(36, weight: FontWeight.w300),
                  children: [
                    const TextSpan(text: 'Daxil ol\n'),
                    TextSpan(
                      text: 'wallet-inə',
                      style: AppTextStyles.display(36, weight: FontWeight.w600, color: AppColors.accent)
                          .copyWith(fontStyle: FontStyle.italic),
                    ),
                    const TextSpan(text: '.'),
                  ],
                ),),

                const SizedBox(height: 48),

                _label('E-poçt'),
                TextFormField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  textInputAction: TextInputAction.next,
                  autocorrect: false,
                  decoration: const InputDecoration(hintText: 'ad@paylo.az'),
                  validator: (v) {
                    if (v == null || v.trim().isEmpty) return 'E-poçt tələb olunur';
                    if (!v.contains('@')) return 'Yanlış e-poçt';
                    return null;
                  },
                ),

                const SizedBox(height: 20),
                _label('Şifrə'),
                TextFormField(
                  controller: _password,
                  obscureText: _obscure,
                  textInputAction: TextInputAction.done,
                  onFieldSubmitted: (_) => _submit(),
                  decoration: InputDecoration(
                    hintText: '••••••••',
                    suffixIcon: IconButton(
                      icon: Icon(_obscure ? Icons.visibility_off : Icons.visibility, size: 18),
                      onPressed: () => setState(() => _obscure = !_obscure),
                    ),
                  ),
                  validator: (v) => (v == null || v.length < 6) ? 'Minimum 6 simvol' : null,
                ),

                const SizedBox(height: 32),
                AppButton(
                  label: 'Daxil ol →',
                  loading: isLoading,
                  onPressed: _submit,
                ),

                const SizedBox(height: 24),
                Center(
                  child: TextButton(
                    onPressed: isLoading ? null : () => context.go('/register'),
                    child: Text(
                      'Hesabın yoxdur? Qeydiyyat →',
                      style: AppTextStyles.mono(11, color: AppColors.muted, letterSpacing: 0.16),
                    ),
                  ),
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
