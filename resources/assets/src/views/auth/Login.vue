<template>
  <form @submit.prevent="login">
    <div class="input-group mb-3">
      <input
        v-model="identification"
        class="form-control"
        :placeholder="$t('auth.identification')"
        required
      >
      <div class="input-group-append">
        <div class="input-group-text">
          <span class="fas fa-envelope" />
        </div>
      </div>
    </div>
    <div class="input-group mb-3">
      <input
        v-model="password"
        type="password"
        class="form-control"
        :placeholder="$t('auth.password')"
        required
      >
      <div class="input-group-append">
        <div class="input-group-text">
          <span class="fas fa-lock" />
        </div>
      </div>
    </div>

    <captcha v-if="tooManyFails" ref="captcha" />

    <div class="alert alert-warning" :class="{ 'd-none': !warningMsg }">
      <i class="icon fas fa-exclamation-triangle" />
      {{ warningMsg }}
    </div>

    <div class="d-flex justify-content-between mb-3">
      <div>
        <label>
          <input v-model="remember" type="checkbox">
          {{ $t('auth.keep') }}
        </label>
      </div>
      <a v-t="'auth.forgot-link'" :href="`${baseUrl}/auth/forgot`" />
    </div>

    <button
      class="btn btn-block btn-primary"
      type="submit"
      :disabled="pending"
    >
      <template v-if="pending">
        <i class="fa fa-spinner fa-spin" /> {{ $t('auth.loggingIn') }}
      </template>
      <span v-else>{{ $t('auth.login') }}</span>
    </button>
  </form>
</template>

<script>
import Captcha from '../../components/Captcha.vue'
import emitMounted from '../../components/mixins/emitMounted'
import { showModal } from '../../scripts/notify'

export default {
  name: 'Login',
  components: {
    Captcha,
  },
  mixins: [
    emitMounted,
  ],
  props: {
    baseUrl: {
      type: String,
      default: blessing.base_url,
    },
  },
  data() {
    return {
      identification: '',
      password: '',
      remember: false,
      tooManyFails: blessing.extra.tooManyFails,
      recaptcha: blessing.extra.recaptcha,
      invisible: blessing.extra.invisible,
      warningMsg: '',
      pending: false,
    }
  },
  methods: {
    async login() {
      const {
        identification, password, remember,
      } = this

      this.pending = true
      const {
        code, message, data: { login_fails: loginFails, redirectTo } = { login_fails: 0 },
      } = await this.$http.post(
        '/auth/login',
        {
          identification,
          password,
          keep: remember,
          captcha: this.tooManyFails
            ? await this.$refs.captcha.execute()
            : void 0,
        },
      )
      if (code === 0) {
        window.location = redirectTo
      } else {
        if (loginFails > 3 && !this.tooManyFails) {
          if (this.recaptcha) {
            if (!this.invisible) {
              showModal({
                mode: 'alert',
                text: this.$t('auth.tooManyFails.recaptcha'),
              })
            }
          } else {
            showModal({
              mode: 'alert',
              text: this.$t('auth.tooManyFails.captcha'),
            })
          }
          this.tooManyFails = true
        }
        this.warningMsg = message
        this.pending = false
        this.$refs.captcha.refresh()
      }
    },
  },
}
</script>
